/**
 * `safeNext` — the ONE reader of a `?next=` parameter in this app.
 *
 * Pure, dependency-free, and called from exactly two places: `src/proxy.ts` (the signed-in bounce
 * out of `/login`, and nothing else) and the login page's server component, which sanitizes the
 * value before it ever becomes a client prop. A second implementation is the bug — the two callers
 * would drift and only one of them would be audited.
 *
 * WHY THIS LIVES IN `lib/auth/` AND NOT `features/auth/`: `proxy.ts` runs on the Node runtime and
 * today imports only `./lib/env`. A middleware importing a `features/` barrel drags client-component
 * code into that module graph — `'use client'` files, React, the query client — for one string
 * function. Nothing in this file may import anything.
 *
 * THE HAZARD. `new URL(rawNext, request.url)` resolves `//evil.com` to `https://evil.com`, so a
 * proxy that builds a `Location` header from an unvalidated `next` is an open redirector reachable
 * by anyone who can get a signed-in admin to click a link. And a naive `startsWith('/')` guard is
 * not enough: browsers normalize `/\evil.com` and `/<TAB>/evil.com` toward `//evil.com`, so both
 * pass that check and both leave our origin.
 *
 * Every rule below returns the same value on doubt — `'/'`, the overview route. There is no throw
 * and no `null`: a caller that has to handle a failure mode will eventually handle it by falling
 * back to the raw value.
 */

/** The origin every candidate is re-parsed against. RFC 2606 reserves `.invalid`; it never resolves,
 *  and it can never collide with a real deployment host, so `url.origin !== SENTINEL` is a reliable
 *  "this value named a host" test rather than a comparison against whatever host we happen to run
 *  on in this environment. */
const SENTINEL_ORIGIN = 'http://x.invalid';

/** Where every rejection lands, and the only value this function invents. */
const FALLBACK = '/';

/**
 * The cap, shared with `proxy.ts`, which also skips producing a `next` at all past this length.
 * Two independent reasons: a `Location` header is not a place to relay an unbounded attacker-chosen
 * string, and a 512-character post-login destination is not a page a human navigated to.
 */
export const MAX_NEXT_LENGTH = 512;

/** The hosted-chat namespace (ADR-027). `proxy.ts` redirects it away from the admin origin anyway;
 *  rejecting it here means a `next` cannot be the thing that carries an admin session to the origin
 *  that renders model output. */
const CHAT_PREFIX = '/c/';

/**
 * The `(auth)` route group's public URLs, as EXACT paths with no prefixes at all.
 *
 * Exported because `proxy.ts` needs the identical set for its "may an anonymous visitor reach this?"
 * test, and `safeNext` needs it for its reject-by-destination rule below. One definition, two
 * callers — the same argument that makes `safeNext` itself a single function.
 *
 * NO PREFIXES, deliberately. All three email landing routes take their token in a QUERY STRING
 * rather than a path segment (approved decision D2), so there is no `[token]` segment to match and
 * no `/invitations/` prefix. That removes the `/invitations-evil` hazard — a prefix without an
 * anchored trailing slash makes a lookalike path public — by removing prefix matching entirely.
 * A path that merely RESEMBLES one of these (`/logins`, `/invitations/accepted`) is not public.
 *
 * `tests/unit/proxy.test.ts` walks `src/app/(auth)/` and asserts the derived URL set equals this
 * one, so adding a page and forgetting this list is a named red test rather than a live route that
 * bounces to `/login` forever.
 */
export const PUBLIC_PATHS: ReadonlySet<string> = new Set([
  '/login',
  '/register',
  '/forgot-password',
  '/reset-password',
  '/verify-email',
  '/invitations/accept',
]);

/** Exact-match only. See PUBLIC_PATHS on why there is no prefix arm. */
export const isPublicPath = (pathname: string): boolean => PUBLIC_PATHS.has(pathname);

/**
 * One regex that rejects four families at once: absolute URLs (`https://evil.com`) and pseudo
 * schemes (`javascript:alert(1)`) have no leading slash; protocol-relative (`//evil.com`) and
 * backslash-relative (`/\evil.com`) have a second separator the negative lookahead refuses.
 */
const PATH_ABSOLUTE_ONLY = /^\/(?![/\\])/;

/**
 * Control characters and backslashes, anywhere in the string.
 *
 * Both are normalization surfaces rather than syntax errors. A browser strips `\t`, `\n` and `\r`
 * from a URL before resolving it, so `/<TAB>/evil.com` becomes `//evil.com` AFTER our regex has
 * already approved it; and every major browser treats `\` as `/` in the authority position, so
 * `/\evil.com` becomes `//evil.com` the same way. Rejecting the characters is the only check that
 * runs before the normalization we do not control.
 */
const BACKSLASH = 0x5c;

function hasUnsafeCharacter(value: string): boolean {
  // charCodeAt throughout rather than `value[i]`: the same check, and it does not read as an
  // object-injection sink to eslint-plugin-security, so this file adds no warning anybody has to
  // learn to ignore.
  for (let i = 0; i < value.length; i += 1) {
    const code = value.charCodeAt(i);
    // C0 controls (which includes TAB, LF and CR), DEL, and the C1 range.
    if (code <= 0x1f || (code >= 0x7f && code <= 0x9f)) return true;
    if (code === BACKSLASH) return true;
  }
  return false;
}

export function safeNext(raw: string | readonly string[] | undefined | null): string {
  // Next hands back a `string[]` for a repeated search param, so `?next=/a&next=//evil.com` arrives
  // as an array. Treating it as `raw[0]` would honour the first value and ignore the second, which
  // is exactly the shape an attacker appends to a link an admin already trusts. Not one string, not
  // a destination.
  if (typeof raw !== 'string') return FALLBACK;

  if (raw.length > MAX_NEXT_LENGTH) return FALLBACK;
  if (hasUnsafeCharacter(raw)) return FALLBACK;
  if (!PATH_ABSOLUTE_ONLY.test(raw)) return FALLBACK;

  // Re-apply both character rules to the PERCENT-DECODED form. `/%2F%2Fevil.com` passes every check
  // above — `%` is not a control character and the string starts with exactly one slash — and then
  // decodes to `///evil.com`. Same for an encoded tab or backslash.
  let decoded: string;
  try {
    decoded = decodeURIComponent(raw);
  } catch {
    // A lone `%` or a truncated escape. Malformed, therefore not a destination.
    return FALLBACK;
  }
  if (hasUnsafeCharacter(decoded)) return FALLBACK;
  if (!PATH_ABSOLUTE_ONLY.test(decoded)) return FALLBACK;

  // Re-parse and re-serialize. This is what drops an embedded host, drops a `#fragment` (which is
  // never sent to a server and has no business in a `Location`), and collapses `..` segments — so
  // what a caller receives is the value the browser would resolve, not the value the attacker typed.
  let url: URL;
  try {
    url = new URL(raw, SENTINEL_ORIGIN);
  } catch {
    return FALLBACK;
  }
  if (url.origin !== SENTINEL_ORIGIN) return FALLBACK;

  const out = `${url.pathname}${url.search}`;
  // Belt: assert the SERIALIZED form against the same regex the raw form passed. A value that
  // re-serializes into something protocol-relative never leaves this function.
  if (!PATH_ABSOLUTE_ONLY.test(out)) return FALLBACK;

  // ── reject by destination ────────────────────────────────────────────────────────────────────
  // Everything from here is a path we could safely navigate to and still should not.
  if (out === FALLBACK) return FALLBACK;

  // The hosted-chat namespace, on the admin origin.
  if (out === '/c' || out.startsWith(CHAT_PREFIX)) return FALLBACK;

  // A public `(auth)` path. `next=/login` is a redirect loop, and `next=/reset-password?token=…`
  // is a token-laundering surface — a single-use credential relayed through a `Location` header and
  // into somebody's proxy logs. Matched on the PATHNAME, so the query string cannot smuggle it past.
  if (isPublicPath(url.pathname)) return FALLBACK;

  return out;
}
