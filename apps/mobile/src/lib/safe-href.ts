/**
 * Deep links are untrusted input from outside the app, and expo-router makes EVERY route
 * deep-linkable automatically — including routes added later by someone who never thought about
 * links. So the validation lives here, once, and the gate calls it.
 *
 * What a link is allowed to do: select WHICH conversation or bot to open.
 * What a link may never do: supply an organization id, an API origin, or any credential, or put
 * the app into an authenticated state it did not earn. The app re-resolves the target against
 * Laravel with its own token and renders the public not-found when the answer says so — a 403 on
 * a foreign identifier would confirm the row exists and turn the screen into an enumeration
 * oracle.
 */

/**
 * True for an in-app path we are willing to navigate to after a login.
 *
 * The intended href is remembered in memory by the session provider, NOT round-tripped through a
 * `?next=` query parameter — a redirect target that survives in a URL is a redirect target an
 * attacker can supply. This function is the second line of defence for the case where one is
 * introduced anyway.
 *
 * Rejected, in order of how often each is the actual bug:
 *  - anything with a scheme (`https:`, `knowledgebot:`, `javascript:`) — an absolute destination
 *  - protocol-relative `//evil.example` — a host, not a path, and it looks like a path
 *  - a backslash variant of the above; some parsers normalise `\\` to `//`
 *  - anything not starting with a single `/`
 *  - the auth group itself, which would bounce a freshly logged-in user back to login
 */
export function isSafeInternalHref(href: unknown): href is string {
  if (typeof href !== 'string' || href === '') return false;
  if (!href.startsWith('/')) return false;
  if (href.startsWith('//') || href.startsWith('/\\')) return false;
  if (href.includes('\\')) return false;
  // A colon before the first slash-delimited segment ends is a scheme. `/chat/a:b` is fine.
  if (/^\/[^/]*:/.test(href)) return false;
  if (href.startsWith('/login')) return false;
  return true;
}

/**
 * Identifiers minted by Laravel are ULIDs: 26 characters, Crockford base32, uppercase. Validating
 * the SHAPE here is not a security control on its own — the server is the authority and re-resolves
 * every id against the caller's own token — but it turns a malformed deep link into a local
 * not-found instead of a request, which keeps a link-driven enumeration attempt off the network
 * entirely.
 */
const ULID = /^[0-9A-HJKMNP-TV-Z]{26}$/;

export function isUlid(value: unknown): value is string {
  return typeof value === 'string' && ULID.test(value);
}

/**
 * `useLocalSearchParams` types a route parameter as `string | string[]`, because a duplicated
 * query key produces an array. A screen that reads it as a string and passes it into a URL will
 * happily interpolate `a,b`. Take the first value and validate it.
 */
export function firstParam(value: string | string[] | undefined): string | undefined {
  if (Array.isArray(value)) return value[0];
  return value;
}
