/**
 * Token expiry arithmetic. Pure functions, no I/O — which is what makes them testable without a
 * keychain and why they live apart from secure-store.ts.
 *
 * Sanctum has NO REFRESH TOKENS. When a personal access token expires the user re-enters a
 * password; there is no silent renewal to fall back on. That makes the grace window below a real
 * user-experience decision, not a defensive constant.
 */

/**
 * A token is treated as ABSENT once it has less than this long to live.
 *
 * 90 seconds, chosen against the chat deadline: a send that opens a stream is authenticated ONCE,
 * when the request starts, so a stream that opened is safe for its whole life and expiry lands on
 * the *next* request. The failure this prevents is the composed message — the user types a
 * paragraph, taps send, and the POST 401s because the token died in the two seconds between
 * mounting the screen and submitting. Refusing pre-flight routes them to login BEFORE the POST,
 * with the draft still in memory.
 *
 * It is not sized to cover a 60 s chat deadline end-to-end, because it does not need to: the
 * stream outlives the token by design. It is sized to cover the gap between the check and the
 * request, plus clock skew.
 */
export const EXPIRY_GRACE_SECONDS = 90;

/**
 * True when the token should be treated as gone.
 *
 * @param expiresAt ISO-8601 timestamp as issued by Laravel, or null. NULL IS EXPIRED, not
 *   eternal: every token this app receives carries a hard `expires_at`, so a missing one means the
 *   record is malformed or was written by an older build. Failing closed costs one login; failing
 *   open keeps a token the server may already have revoked.
 * @param now Injectable for tests. Never read the clock twice in one decision.
 */
export function isExpiringSoon(
  expiresAt: string | null | undefined,
  now: number = Date.now(),
): boolean {
  if (expiresAt === null || expiresAt === undefined || expiresAt === '') return true;

  const at = Date.parse(expiresAt);
  // An unparseable timestamp is also expired. `Date.parse` returns NaN and every comparison
  // against NaN is false, so an unguarded `at - now < grace` would report "plenty of time left".
  if (Number.isNaN(at)) return true;

  return at - now < EXPIRY_GRACE_SECONDS * 1000;
}

/** Seconds of usable life remaining, floored at 0. For diagnostics and the device list only. */
export function secondsUntilExpiry(
  expiresAt: string | null | undefined,
  now: number = Date.now(),
): number {
  if (expiresAt === null || expiresAt === undefined || expiresAt === '') return 0;
  const at = Date.parse(expiresAt);
  if (Number.isNaN(at)) return 0;
  return Math.max(0, Math.floor((at - now) / 1000));
}
