import { KbError, type SessionResource } from '@kb/contracts';
import { http, HttpResponse } from 'msw';
import { afterEach, describe, expect, it } from 'vitest';

import { refreshCsrfToken } from '@/lib/api/browser';

import { envelope, ORIGIN } from '../msw/handlers';
import { worker } from '../msw/setup';

/**
 * The harness proving itself, so that Batch 3's component specs fail on THEIR assertions rather than
 * on a mock layer that was never intercepting anything. Every failure mode below produces a TIMEOUT
 * rather than a useful message if it is discovered from inside a form spec.
 *
 * Nothing here renders a component. It is in `tests/components/` because that is where Browser Mode
 * runs — the service worker transport only exists in a real browser, and `setupServer` is the wrong
 * mechanism here for the reason tests/msw/handlers.ts documents at length.
 */

describe('the service worker is actually intercepting', () => {
  it('answers a cross-origin api.invalid request from the handler set', async () => {
    // `.invalid` never resolves (RFC 2606). A 200 here can only have come from the worker, so this
    // single assertion covers "mockServiceWorker.js is present, registered, and matched".
    const response = await fetch(`${ORIGIN}/api/v1/me`);
    expect(response.status).toBe(200);

    // The `data` envelope is asserted here rather than unwrapped silently, because it is the one
    // cross-plane shape a component spec can get wrong without noticing: Laravel wraps EVERY success
    // body in `data` (`ResponseShape` maps a response key to a schema class, so an unwrapped body is
    // unpublishable by `kb:dump-openapi`, and both pre-auth endpoints already wrap). A fixture that
    // returned the bare resource would let every form spec pass against a shape the server never
    // sends — so this assertion is the harness proving the envelope, not just the interception.
    const body = (await response.json()) as { data: SessionResource };
    expect(Object.keys(body)).toEqual(['data']);
    expect(body.data.user.email).toBe('ada@example.test');
    // Two ACTIVE organizations, because a one-organization fixture cannot distinguish an org switch
    // from a refetch.
    expect(body.data.organizations).toHaveLength(2);
    expect(body.data.current_organization_id).toBe(body.data.organizations[0]?.id);
  });

  it('never lets an unhandled request escape to the network', async () => {
    // `onUnhandledRequest: 'error'`. With 'warn' or 'bypass' the request escapes to a host that does
    // not resolve, the spec waits for a render that never comes, and the reported failure names the
    // timeout rather than the missing handler.
    //
    // MEASURED, not assumed, because the mechanism is not the obvious one: under the SERVICE WORKER
    // transport the promise does NOT reject. The worker answers `500 Request Handler Error` with a
    // body naming the strategy. That still satisfies the property this option is for — the request is
    // stopped and the reason is legible — but a spec asserting `.rejects` here goes red against a
    // correctly configured harness, which is how a working guard gets deleted.
    const response = await fetch(`${ORIGIN}/api/v1/not-a-real-endpoint`);
    expect(response.status).toBe(500);
    expect(response.statusText).toBe('Request Handler Error');
    expect(await response.text()).toContain('onUnhandledRequest');
  });
});

describe('per-spec overrides do not leak into the next spec', () => {
  it('honours a worker.use override', async () => {
    worker.use(
      http.post(`${ORIGIN}/api/v1/auth/login`, () =>
        HttpResponse.json(envelope('rate_limit', { retryable: true }), {
          status: 429,
          headers: { 'Retry-After': '30' },
        }),
      ),
    );

    const response = await fetch(`${ORIGIN}/api/v1/auth/login`, { method: 'POST' });
    expect(response.status).toBe(429);
    expect(response.headers.get('Retry-After')).toBe('30');
  });

  it('is back on the default handler in the very next spec', async () => {
    // The `afterEach(worker.resetHandlers)` in tests/msw/setup.ts. Without it the 429 above leaks and
    // the failure surfaces in whichever spec happens to run afterwards — the worst possible place.
    const response = await fetch(`${ORIGIN}/api/v1/auth/login`, { method: 'POST' });
    expect(response.status).toBe(200);
  });
});

describe('the document.cookie seed every future spec needs', () => {
  afterEach(() => {
    document.cookie = 'XSRF-TOKEN=; max-age=0';
  });

  it('THROWS the cookie-absent KbError when the spec forgot to seed it', async () => {
    // This is the trap, demonstrated once so nobody debugs it twice. `readCookie` reads the PAGE's
    // document.cookie, and MSW cannot set a cookie for a cross-origin host from a worker — the
    // Set-Cookie on the api.invalid response is simply not applied here. So the handler answers 204,
    // the cookie is still absent, and the code under test throws with error_class null: unknown, and
    // unknown is permanently non-retryable. Never an invented class.
    const error = await refreshCsrfToken().then(
      () => null,
      (cause: unknown) => cause,
    );

    expect(error).toBeInstanceOf(KbError);
    expect((error as KbError).error_class).toBeNull();
    expect((error as KbError).retryable).toBe(false);
  });

  it('resolves the decoded token once the spec seeds document.cookie', async () => {
    // What every form spec must do in a beforeEach. The `%3D` is not decoration: Laravel URL-encodes
    // the cookie, and echoing the raw padding as X-XSRF-TOKEN 419s every mutation while the cookie
    // is perfectly valid.
    document.cookie = 'XSRF-TOKEN=eyJpdiI6InQ%3D%3D';

    await expect(refreshCsrfToken()).resolves.toBe('eyJpdiI6InQ==');
  });
});
