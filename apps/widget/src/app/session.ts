import { KbError } from '@kb/contracts';

import type { SessionGrant } from './bridge.js';

/**
 * The chat-session bearer, and the only credential this application ever holds.
 *
 * WHERE IT LIVES: a module-scoped variable in the iframe's JS realm, on our origin. Nowhere else.
 *
 *   - NOT the origin-persistent web-storage API, and not IndexedDB. Barred for a session
 *     identifier outright (OWASP), and under Firefox's Total Cookie Protection a third-party frame
 *     whose domain lands on Mozilla's tracker list gets `SecurityError` from both, plus the cache
 *     store, the cross-tab broadcast channel, shared workers and service workers. A widget
 *     deployed across thousands of sites eventually lands on such a list, and the failure is one
 *     customer, one browser, no reproduction. The identifiers are banned by name in
 *     eslint.config.mjs — deliberately not repeated here, because CI greps this tree for them.
 *   - NOT `sessionStorage`. That tier exists (resume.ts) and holds a resumption id, which carries
 *     no authority; a bearer parked in partitioned storage is replayable by anything that can read
 *     that partition and outlives the tab that earned it.
 *   - NOT a URL, fragment included. A fragment is not sent to the server but it still lands in
 *     `location`, in the frame's history entry, and in whatever the customer's analytics scrapes
 *     off the DOM.
 *   - NOT a `window.` global and NOT reachable from the host page: the host document is a
 *     different origin and cannot read this realm at all.
 *
 * It does sit in host-page JS for one tick, on its way over the bridge. That is not a downgrade:
 * any script on an allow-listed page can mint its own from the same endpoint. What makes it
 * acceptable is the blast radius — one bot, one origin, three abilities, 30 minutes.
 */

let token: string | null = null;
/** Epoch ms. Used only to decide when to ASK the loader for a refresh, never trusted as authority. */
let expiresAt = 0;

/** Renew proactively this far ahead of expiry. */
const REFRESH_MARGIN_MS = 5 * 60 * 1000;

export function setSession(grant: SessionGrant): void {
  token = grant.token;
  expiresAt = Date.now() + grant.expires_in * 1000;
  // THE RESOLUTION POINT of an in-flight refresh. The loader's answer to `session-expiring` is a
  // `session` message, which lands in bridge.ts's `onSession` handler, which calls this — so this
  // assignment is the only evidence the frame ever gets that a re-mint succeeded. Draining the
  // queue here rather than in `refreshSession` keeps that fact in one place: there is no second
  // path by which a new bearer can arrive, and therefore no second place a queued send could be
  // released from with a token nobody checked.
  settleRefresh(null);
}

export function clearSession(): void {
  token = null;
  expiresAt = 0;
}

export function hasSession(): boolean {
  return token !== null;
}

export function isExpiringSoon(): boolean {
  return token !== null && Date.now() > expiresAt - REFRESH_MARGIN_MS;
}

/**
 * The bearer, as a header. Returned as a fresh object each call so a caller cannot retain and
 * mutate the credential, and never exported as a raw string getter.
 */
export function authHeaders(): Record<string, string> {
  // `security/detect-possible-timing-attacks` fires on any comparison whose identifier matches
  // /token|secret|password|hash/. This is a NULL CHECK on our own module-scoped variable — there is
  // no attacker-supplied operand, no secret-to-secret comparison, and nothing whose duration leaks
  // anything. Suppressed with a reason rather than left standing, because an unexplained
  // "timing attack" warning on the one line that handles the bearer is exactly the kind of noise
  // that gets a real finding waved through later.
  // eslint-disable-next-line security/detect-possible-timing-attacks -- null check, not a comparison of secrets
  if (token === null) throw new Error('[kb] no session');
  return { authorization: `Bearer ${token}` };
}

/**
 * The refresh gate.
 *
 * The frame can NEVER refresh itself: every request it makes carries `Origin: <widget-domain>`,
 * our own origin, which proves nothing about which page is embedding us. Origin proof exists in
 * exactly one place — the loader's POST from the customer's page. So the frame notices and ASKS;
 * the loader acts.
 *
 * The contract this must satisfy when implemented:
 *   - ONE refresh at a time. Two simultaneous 401s produce one mint; two would burn the
 *     `sdk-bootstrap` composite limiter and orphan a session.
 *   - Sends issued during the window are QUEUED IN MEMORY, IN ORDER, and flushed when the new
 *     token lands. Never dropped, never retried against the dead token. Each keeps the
 *     `Idempotency-Key` it was created with, so a send that reached Laravel before the 401 cannot
 *     be double-posted by the flush.
 *   - If the refresh fails, or does not answer within 10 s, the queue fails ONCE and VISIBLY with
 *     `error_class: authentication` and a "reload to continue" affordance. A queue that retries
 *     forever is the same dead conversation with a spinner on top.
 *
 * `request` is INJECTED, and that is what makes this function testable without a network: every
 * call site passes `bridge.requestSessionRefresh` (app/bridge.ts), which is
 * `toHost('session-expiring')` and nothing else. This module never opens a socket, never learns the
 * API origin, and never sees the reply — the reply arrives as a `session` message and lands in
 * `setSession` above.
 */

/** How long the frame waits for the loader's answer before failing every queued send. */
export const REFRESH_TIMEOUT_MS = 10_000;

/** The in-flight refresh, shared by every caller. Non-null means "one is already running". */
let pending: Promise<void> | null = null;
let settle: { resolve: () => void; reject: (cause: unknown) => void } | null = null;
let timer: ReturnType<typeof setTimeout> | undefined;

/**
 * Set when a refresh has already failed, and deliberately NOT cleared by a later attempt.
 *
 * "The queue fails ONCE and VISIBLY" is only true if nothing re-arms afterwards. Without this flag
 * the next send to see a 401 would start a fresh refresh, ask the loader again, wait another ten
 * seconds and fail again — the same dead conversation with a spinner on top, and a message loop
 * against the loader's `sdk-bootstrap` composite limiter on top of that. The affordance is a
 * reload; the state is cleared only by a grant genuinely arriving (`setSession`).
 */
let refusedUntilNewGrant = false;

function authError(reason: string): KbError {
  // `error_class: 'authentication'` per the contract above. `retryable: false` is the taxonomy's
  // answer for a rejected credential and is STATED rather than inferred, because after ADR-029 a
  // class name no longer answers the retry question on its own.
  return new KbError('authentication', false, null, null, reason);
}

/**
 * Resolve or reject the in-flight refresh exactly once, and always tear the timer down with it.
 *
 * Every caller shares ONE promise, so "the queue fails once" is a property of the promise rather
 * than of a loop: a settled promise ignores later settlement attempts, and each awaiting send is
 * resumed exactly once, in the order it started awaiting. There is no separate array to keep in
 * step — the promise's own reaction list IS the queue, which is why a send cannot be dropped,
 * double-flushed, or reordered by a bug in bookkeeping code that does not exist.
 */
function settleRefresh(cause: unknown): void {
  if (timer !== undefined) {
    clearTimeout(timer);
    timer = undefined;
  }
  const target = settle;
  pending = null;
  settle = null;
  // A grant arriving is what un-poisons the module, whether or not anyone was waiting for it: the
  // loader can answer late, after the timeout already failed the queue, and a live bearer that the
  // frame refuses to use is a working session behind a permanent error.
  refusedUntilNewGrant = cause !== null;
  if (target === null) return;
  if (cause === null) {
    target.resolve();
    return;
  }
  target.reject(cause);
}

export function refreshSession(request: () => void): Promise<void> {
  // Already failed once. Answer immediately with the same class, ask the loader nothing, and start
  // no timer: a caller that arrives after the failure gets the failure, not a second ten-second
  // wait ending in the same place.
  if (refusedUntilNewGrant) {
    return Promise.reject(authError('session refresh already failed; reload to continue'));
  }

  // SINGLE FLIGHT. Two simultaneous 401s must produce ONE `session-expiring` and one mint — two
  // would burn the `sdk-bootstrap` composite limiter and orphan a session. The second caller joins
  // the first caller's promise, which is also how it ends up in the queue behind it.
  if (pending !== null) return pending;

  pending = new Promise<void>((resolve, reject) => {
    settle = { resolve, reject };
  });
  // The shared promise may be rejected before a given send gets around to awaiting it (a
  // synchronous timeout in a test, a caller that stores it first). Attaching an inert handler here
  // keeps that from surfacing as an unhandled rejection in the frame's console; every real caller
  // still sees the rejection through its own await.
  const queued = pending;
  void queued.catch(() => {});

  timer = setTimeout(() => {
    // The loader never answered. It may have failed the mint (it emits its own REFRESH_FAILED to
    // the host page), or the customer's `connect-src` may block api.<domain> entirely — from in
    // here those are indistinguishable, and both mean the same thing: this conversation is over
    // until the visitor reloads.
    settleRefresh(authError(`session refresh timed out after ${REFRESH_TIMEOUT_MS} ms`));
  }, REFRESH_TIMEOUT_MS);

  // ASK, do not act. The frame can never refresh itself: every request it makes carries
  // `Origin: <widget-domain>`, our own origin, which proves nothing about which page is embedding
  // us. Origin proof exists in exactly one place — the loader's POST from the customer's page.
  request();

  return queued;
}

/**
 * The frame gave up on its own, before the timeout: the loader answered `refresh_failed`, or the
 * app is tearing down. Fails the queue once with the same class the timeout uses.
 *
 * Exported for the app shell, not for tests — a send that is waiting must not be left waiting by a
 * teardown path that only clears the token.
 */
export function failRefresh(reason = 'session refresh failed'): void {
  if (pending === null) return;
  settleRefresh(authError(reason));
}
