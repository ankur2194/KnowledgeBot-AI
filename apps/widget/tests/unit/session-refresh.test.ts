import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

// TYPE-ONLY, so it creates no second class and no second module instance — it is the shape, not the
// constructor. The constructor comes from `freshModule()`, for the reason given there.
import type { KbError as KbErrorShape } from '@kb/contracts';

import type * as SessionNamespace from '../../src/app/session.js';

/**
 * THE REFRESH GATE (src/app/session.ts), which is testable at this layer for one specific reason:
 * `refreshSession(request)` takes the ask as a CALLBACK. In the app that callback is
 * `bridge.requestSessionRefresh` — `toHost('session-expiring')` — so the function never opens a
 * socket, never learns an origin, and never sees the answer. The answer arrives as a `session`
 * message, which lands in `setSession()`.
 *
 * That makes the whole three-clause contract local, and it is the contract these tests pin:
 *
 *   1. ONE refresh at a time. Two simultaneous 401s produce ONE `session-expiring` — two would burn
 *      the `sdk-bootstrap` composite limiter and orphan a session.
 *   2. Sends issued during the window are QUEUED IN MEMORY, IN ORDER, and flushed when the new token
 *      lands — never dropped, never retried against the dead token.
 *   3. On failure or a 10 s timeout the queue fails ONCE and VISIBLY with
 *      `error_class: 'authentication'`, and nothing re-arms. A queue that retries forever is the
 *      same dead conversation with a spinner on top.
 *
 * The module holds process-wide state on purpose (the bearer lives in one module-scoped variable and
 * nowhere else), so every test re-imports it through `vi.resetModules()` rather than calling a
 * reset export that would exist only for tests.
 */

type SessionModule = typeof SessionNamespace;
type KbErrorClass = typeof KbErrorShape;

/**
 * `KbError` is re-imported ALONGSIDE the module under test, and that detail is worth stating.
 * `vi.resetModules()` gives the reloaded session module a fresh copy of `@kb/contracts`, so an
 * `instanceof` against a `KbError` imported at the top of this file compares two different classes
 * and fails — which is exactly the production failure the "one KbError, declared once" rule exists
 * to prevent, reproduced here by a module registry rather than by a second declaration. Taking both
 * from the same registry is what makes the assertion mean what it says.
 */
async function freshModule(): Promise<{ session: SessionModule; KbError: KbErrorClass }> {
  vi.resetModules();
  const session = await import('../../src/app/session.js');
  const contracts = await import('@kb/contracts');
  return { session, KbError: contracts.KbError };
}

beforeEach(() => {
  vi.useFakeTimers();
});

afterEach(() => {
  vi.useRealTimers();
});

describe('refreshSession', () => {
  it('asks the loader ONCE for any number of concurrent callers', async () => {
    const { session } = await freshModule();
    session.setSession({ token: 'kbw_old', expires_in: 1800 });

    let asks = 0;
    const request = (): void => {
      asks += 1;
    };

    const first = session.refreshSession(request);
    const second = session.refreshSession(request);
    const third = session.refreshSession(request);

    expect(asks).toBe(1);
    // The same promise, which is also what makes it the queue: every waiter is a reaction on one
    // object, so there is no separate bookkeeping array to fall out of step with reality.
    expect(second).toBe(first);
    expect(third).toBe(first);

    session.setSession({ token: 'kbw_new', expires_in: 1800 });
    await expect(first).resolves.toBeUndefined();
  });

  it('flushes queued sends IN ORDER, each with the NEW bearer', async () => {
    const { session } = await freshModule();
    session.setSession({ token: 'kbw_old', expires_in: 1800 });

    const flushed: string[] = [];
    const send = async (name: string): Promise<void> => {
      await session.refreshSession(() => {});
      // `authHeaders()` is read at FLUSH time, not captured at queue time: the whole point of
      // queueing rather than retrying is that the send goes out against the replacement token.
      flushed.push(`${name}:${session.authHeaders()['authorization']}`);
    };

    const a = send('a');
    const b = send('b');
    const c = send('c');

    session.setSession({ token: 'kbw_new', expires_in: 1800 });
    await Promise.all([a, b, c]);

    expect(flushed).toEqual(['a:Bearer kbw_new', 'b:Bearer kbw_new', 'c:Bearer kbw_new']);
  });

  it('fails every queued send ONCE, with error_class authentication, after 10 s of silence', async () => {
    const { session, KbError } = await freshModule();
    session.setSession({ token: 'kbw_old', expires_in: 1800 });

    const first = session.refreshSession(() => {});
    const second = session.refreshSession(() => {});

    const rejections: unknown[] = [];
    const capture = (promise: Promise<void>): Promise<void> =>
      promise.then(
        () => {
          rejections.push('resolved');
        },
        (cause: unknown) => {
          rejections.push(cause);
        },
      );
    const settled = Promise.all([capture(first), capture(second)]);

    // Not yet: a queue that gives up early is a conversation dropped while the loader was still
    // minting.
    await vi.advanceTimersByTimeAsync(session.REFRESH_TIMEOUT_MS - 1);
    expect(rejections).toHaveLength(0);

    await vi.advanceTimersByTimeAsync(1);
    await settled;

    expect(rejections).toHaveLength(2);
    for (const rejection of rejections) {
      expect(rejection).toBeInstanceOf(KbError);
      expect((rejection as KbErrorShape).error_class).toBe('authentication');
      // STATED, never inferred from the class name (ADR-029).
      expect((rejection as KbErrorShape).retryable).toBe(false);
    }
  });

  it('does not re-arm after a failure: the next caller fails immediately and the loader is not asked again', async () => {
    const { session, KbError } = await freshModule();
    session.setSession({ token: 'kbw_old', expires_in: 1800 });

    let asks = 0;
    const request = (): void => {
      asks += 1;
    };

    const first = session.refreshSession(request);
    const firstSettled = first.catch((cause: unknown) => cause);
    await vi.advanceTimersByTimeAsync(session.REFRESH_TIMEOUT_MS);
    expect(await firstSettled).toBeInstanceOf(KbError);
    expect(asks).toBe(1);

    // A later send meets the same dead session. It gets the same answer with no second ten-second
    // wait and no second ping at the loader — the affordance is a reload, not a retry ladder.
    const later = session.refreshSession(request);
    await expect(later).rejects.toBeInstanceOf(KbError);
    expect(asks).toBe(1);
    // And no timer was armed, so nothing is left pending to fire later.
    expect(vi.getTimerCount()).toBe(0);
  });

  it('a grant that arrives late un-poisons the module', async () => {
    const { session } = await freshModule();
    session.setSession({ token: 'kbw_old', expires_in: 1800 });

    const failed = session.refreshSession(() => {}).catch((cause: unknown) => cause);
    await vi.advanceTimersByTimeAsync(session.REFRESH_TIMEOUT_MS);
    await failed;

    // The loader answered after the timeout. A live bearer the frame refuses to use is a working
    // session behind a permanent error.
    session.setSession({ token: 'kbw_late', expires_in: 1800 });
    expect(session.authHeaders()['authorization']).toBe('Bearer kbw_late');

    let asks = 0;
    const next = session.refreshSession(() => {
      asks += 1;
    });
    expect(asks).toBe(1);
    session.setSession({ token: 'kbw_later', expires_in: 1800 });
    await expect(next).resolves.toBeUndefined();
  });

  it('failRefresh fails the queue with the same class, without waiting out the timeout', async () => {
    const { session, KbError } = await freshModule();
    session.setSession({ token: 'kbw_old', expires_in: 1800 });

    const pending = session.refreshSession(() => {});
    const settled = pending.catch((cause: unknown) => cause);
    session.failRefresh('loader answered refresh_failed');

    const cause = await settled;
    expect(cause).toBeInstanceOf(KbError);
    expect((cause as KbErrorShape).error_class).toBe('authentication');
    expect(vi.getTimerCount()).toBe(0);
  });

  it('isExpiringSoon is what triggers the PROACTIVE ask, five minutes ahead of expiry', async () => {
    const { session } = await freshModule();

    session.setSession({ token: 'kbw_old', expires_in: 1800 });
    expect(session.isExpiringSoon()).toBe(false);

    // 25 minutes in: inside the five-minute margin of a thirty-minute grant.
    vi.advanceTimersByTime(25 * 60 * 1000 + 1);
    expect(session.isExpiringSoon()).toBe(true);
  });
});
