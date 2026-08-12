import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import { isErrorClass } from '@kb/contracts';

import { FRAME_BLOCKED, degrade, resetDegradeForTest } from '../../src/loader/degrade.js';
import { MINT_FAILED, REFRESH_FAILED } from '../../src/loader/bridge.js';
import type { SdkErrorPayload } from '../../src/bridge/protocol.js';

/**
 * THE PUBLIC SHAPE OF THE §8.20 `error` SDK EVENT.
 *
 * This payload is a commitment to strangers' code: it is delivered to `onEvent` on a customer's own
 * page, into analytics we will never see, and there is no envelope beside it to consult. So the
 * shape is pinned exactly — `toEqual`, not `toMatchObject` — because an ADDED field is as much a
 * change to the contract as a removed one.
 *
 * WHAT THIS LAYER PROVES, and it is a narrow claim: the payload constants and the one call that
 * emits `FRAME_BLOCKED`. It proves nothing about origin checks (a same-origin test passes with every
 * origin check removed — tests/e2e/origin-checks.spec.ts owns those against the two-origin harness)
 * and nothing about the CSP degrade path actually firing on a real blocked frame, which is
 * tests/e2e/csp-degrade.spec.ts.
 *
 * THE RULE UNDER TEST (ADR-029, finding O1). `internal_dependency` gained an `origin` axis:
 * `downstream` renders 503/retryable, `self` renders 500/not-retryable, and the axis is deliberately
 * NOT on the wire. An `error_class` therefore no longer answers "may I retry" on its own, so every
 * error surface states `retryable` explicitly. These assertions are what stops the field being
 * dropped again.
 */

/** A minimal stand-in for the shadow root `degrade()` writes into. The unit project runs in `node`
 *  on purpose (vitest.config.ts), so there is no DOM — and `degrade()` is written so nothing touches
 *  one at import time, which is exactly the property that lets this test exist at all. */
interface FakeElement {
  className: string;
  dataset: Record<string, string>;
  href: string;
  target: string;
  rel: string;
  textContent: string;
}

let children: FakeElement[] = [];
const shadowRoot = {
  replaceChildren: (...nodes: FakeElement[]): void => {
    children = nodes;
  },
} as unknown as ShadowRoot;

beforeEach(() => {
  children = [];
  resetDegradeForTest();
  (globalThis as unknown as { document: unknown }).document = {
    createElement: (): FakeElement => ({
      className: '',
      dataset: {},
      href: '',
      target: '',
      rel: '',
      textContent: '',
    }),
  };
});

afterEach(() => {
  delete (globalThis as unknown as { document?: unknown }).document;
});

describe('the `error` SDK event payload', () => {
  it('states BOTH the class and `retryable` on every constant — a class name is not a retry answer', () => {
    for (const payload of [FRAME_BLOCKED, MINT_FAILED, REFRESH_FAILED]) {
      // `Object.hasOwn`, not a truthiness check: `retryable: false` is the value we most want to
      // assert is PRESENT, and `if (payload.retryable)` would read a missing field identically.
      expect(Object.hasOwn(payload, 'retryable')).toBe(true);
      expect(typeof payload.retryable).toBe('boolean');
      expect(isErrorClass(payload.error_class)).toBe(true);
    }
  });

  it('never carries the envelope `message` — it is operator-facing and this payload lands in a page we do not control', () => {
    for (const payload of [FRAME_BLOCKED, MINT_FAILED, REFRESH_FAILED]) {
      expect(Object.hasOwn(payload, 'message')).toBe(false);
      // No hostname, no stack, no upstream provider text.
      expect(JSON.stringify(payload)).not.toMatch(/http|stack|Error:/i);
    }
  });
});

describe('degrade(): the frame could not be rendered on this page', () => {
  it('emits exactly one `error` event, and its payload is FRAME_BLOCKED verbatim', () => {
    const events: Array<[string, unknown]> = [];
    degrade(shadowRoot, 'pub_01J8FIXTUREBOT0000000000', 'right', (type, payload) => {
      events.push([type, payload]);
    });

    expect(events).toHaveLength(1);
    expect(events[0]?.[0]).toBe('error');
    // Exact, not partial: an added field changes the public contract too.
    expect(events[0]?.[1]).toEqual({
      error_class: 'authorization',
      retryable: false,
      reason: 'frame_blocked',
    } satisfies SdkErrorPayload);
    // The fallback anchor is still installed — the event is in addition to the affordance, not
    // instead of it.
    expect(children).toHaveLength(1);
  });

  it('classifies a blocked frame as `authorization`, NOT `internal_dependency`', () => {
    // The remedy is "fix the embedding configuration", on one side or the other: either the
    // customer's CSP has no `frame-src` for <widget-domain>, or their origin is not on the bot's
    // allow-list so Laravel served `frame-ancestors 'none'`. Nothing downstream of us failed and we
    // have no defect, which is what rules out BOTH sub-cases of `internal_dependency` — and that row
    // additionally pages, so every misconfigured customer page would read as our own outage.
    expect(FRAME_BLOCKED.error_class).toBe('authorization');
    expect(FRAME_BLOCKED.error_class).not.toBe('internal_dependency');
  });

  it('is NOT retryable: no number of attempts changes a Content-Security-Policy', () => {
    expect(FRAME_BLOCKED.retryable).toBe(false);
  });

  it('fires once even when both signals do — the customer gets one link and one event', () => {
    const events: Array<[string, unknown]> = [];
    const onEvent = (type: string, payload?: unknown): void => {
      events.push([type, payload]);
    };
    degrade(shadowRoot, 'pub_01J8FIXTUREBOT0000000000', 'right', onEvent);
    degrade(shadowRoot, 'pub_01J8FIXTUREBOT0000000000', 'right', onEvent);

    expect(events).toHaveLength(1);
  });

  it('survives structuredClone: the payload is JSON-shaped and could cross a postMessage boundary', () => {
    for (const payload of [FRAME_BLOCKED, MINT_FAILED, REFRESH_FAILED]) {
      expect(structuredClone(payload)).toEqual(payload);
    }
  });

  it('is FROZEN — a host-page handler receives it by reference and must not be able to rewrite `retryable` for every later event', () => {
    for (const payload of [FRAME_BLOCKED, MINT_FAILED, REFRESH_FAILED]) {
      expect(Object.isFrozen(payload)).toBe(true);
    }
    // Sloppy mode swallows the write; strict mode throws inside THEIR handler. Either way our value
    // survives, which is the property that matters.
    const hostile = FRAME_BLOCKED as { retryable: boolean };
    try {
      hostile.retryable = true;
    } catch {
      /* strict-mode TypeError, and that is a pass too */
    }
    expect(FRAME_BLOCKED.retryable).toBe(false);
  });
});
