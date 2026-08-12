import { KbError, toKbError } from '@kb/contracts';

import { API_ORIGIN } from '@/lib/env';

/**
 * The ONE browser-side Laravel caller. Every tenant-scoped read and write in this app goes through
 * here, from the browser, so no Next.js server cache can hold a byte of it.
 *
 * TWO SURFACES, TWO ORIGINS, TWO CREDENTIALS — and no helper may assume one of them. The admin
 * console on `app.<domain>` holds the session cookie plus `X-XSRF-TOKEN`; hosted chat on
 * `chat.<domain>` holds the opaque chat-session token as a bearer and NO cookie, because the
 * session cookie is scoped to `app.` and `api.` only. In local development all three are localhost
 * on different ports, so a cookie-assuming helper passes every dev test and 401s in production on
 * exactly one surface: `credentials: 'include'` with no matching cookie is silently empty, not an
 * error. That is why the credential is an argument, never an ambient assumption.
 */
export type Credential =
  | {
      /** Admin console: cookie + CSRF header. Laravel 13's PreventRequestForgery skips token
       *  validation only on `Sec-Fetch-Site: same-origin`, and app. -> api. is same-SITE, not
       *  same-origin, so every mutation falls through to token validation. */
      readonly kind: 'session';
      /** The URL-DECODED XSRF-TOKEN cookie value, echoed as X-XSRF-TOKEN. */
      readonly xsrf_token: string;
    }
  | {
      /** Hosted chat: the same opaque chat-session token the widget uses, as a bearer. */
      readonly kind: 'chat_session';
      readonly token: string;
    };

export interface BrowserRequest {
  /** Path only, e.g. `/api/v1/sources`. The origin comes from NEXT_PUBLIC_API_ORIGIN. */
  readonly path: string;
  readonly method?: 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE';
  readonly body?: unknown;
  readonly credential: Credential;
  /** Forwarded to fetch so `queryClient.cancelQueries()` aborts the request and not just its
   *  result. A queryFn that drops this makes cancelQueries a no-op. */
  readonly signal?: AbortSignal;
  /** Mutations that must survive a retry carry one; one without a key must never be retried by
   *  anything, including a double-click (kb-internal-api-contracts). */
  readonly idempotency_key?: string;
}

/**
 * Resolves with the parsed JSON body, or REJECTS with the `KbError` from `@kb/contracts` — never a
 * per-app copy, because `instanceof` against a forked class fails and the retry predicate in
 * lib/query/client.ts then treats every error as permanent. Built from:
 *   - the `{error_class, message, retryable, request_id}` envelope, and
 *   - `retry_after` parsed from the `Retry-After` RESPONSE HEADER (it is not in the JSON).
 * A response with no parseable envelope yields `error_class: null` — unknown, and unknown is
 * permanently non-retryable. Never invent a class name to fill the slot.
 */
export async function browserFetch<T>(request: BrowserRequest): Promise<T> {
  let credential = request.credential;
  let response = await send(request, credential);

  // 419 is CSRF, and CSRF is a SESSION-ONLY concept. A bearer-authenticated chat_session request
  // that somehow produced a 419 has no XSRF-TOKEN cookie to refresh — retrying it would send the
  // same request twice and then bounce an anonymous visitor to an admin login page they have no
  // business seeing.
  if (response.status === 419 && credential.kind === 'session') {
    credential = { kind: 'session', xsrf_token: await refreshCsrfToken() };
    response = await send(request, credential);

    // Still 419 after a fresh token means the SESSION is gone, not the token. A FULL DOCUMENT
    // navigation, never router.push: the Router Cache lives in the tab and is keyed by path, so a
    // soft navigation leaves authenticated RSC payloads that the back button will render.
    if (response.status === 419) {
      window.location.assign('/login');
      throw await toKbError(response);
    }
  }

  if (!response.ok) throw await toKbError(response);

  // 204/205 carry no body by definition and `json()` on one rejects with a SyntaxError that would
  // surface as an unparseable `unknown` failure over a request that actually succeeded.
  if (response.status === 204 || response.status === 205) return undefined as T;

  return (await response.json()) as T;
}

/** One request, built from the credential union and nothing ambient. */
function send(request: BrowserRequest, credential: Credential): Promise<Response> {
  const headers: Record<string, string> = { Accept: 'application/json' };

  if (request.body !== undefined) headers['Content-Type'] = 'application/json';
  if (request.idempotency_key !== undefined) headers['Idempotency-Key'] = request.idempotency_key;

  // THE WHOLE DESIGN IS THIS SWITCH. `credentials` is decided by the credential, never by the
  // surface the code happens to be running on.
  let credentials: RequestCredentials;
  if (credential.kind === 'session') {
    credentials = 'include';
    // Already URL-DECODED by refreshCsrfToken(). Laravel compares the header to the decrypted
    // cookie value, and `%3D` padding echoed verbatim never matches.
    headers['X-XSRF-TOKEN'] = credential.xsrf_token;
  } else {
    // 'omit', and it is a security control rather than a default. The session cookie is scoped to
    // app. and api.; hosted chat runs on chat., so 'include' would be silently empty there today —
    // and the day the scope widens by one config line it becomes a cross-surface credential leak
    // from the surface that renders model output. 'omit' cannot become that.
    credentials = 'omit';
    headers['Authorization'] = `Bearer ${credential.token}`;
  }

  return fetch(`${API_ORIGIN}${request.path}`, {
    method: request.method ?? 'GET',
    headers,
    credentials,
    // No Next.js cache reaches a browser fetch, but the browser's own HTTP cache does, and it is
    // keyed by URL — which does not carry the organization either.
    cache: 'no-store',
    ...(request.body === undefined ? {} : { body: JSON.stringify(request.body) }),
    ...(request.signal === undefined ? {} : { signal: request.signal }),
  });
}

/**
 * 419 is the session-expiry path nobody writes: a session-authenticated mutation fails CSRF BEFORE
 * it fails auth, so an idle admin gets `419 CSRF token mismatch`, not 401. Re-fetch
 * `/sanctum/csrf-cookie`, retry once, then a FULL DOCUMENT navigation to /login — a client-side
 * push leaves the Router Cache holding authenticated payloads that the back button will render.
 */
export async function refreshCsrfToken(): Promise<string> {
  // Registered at services/core-api/routes/web.php — `config/sanctum.php` sets `'routes' => false`,
  // so that is the ONLY registration and it sits in the one cookie-bearing group. `credentials:
  // 'include'` is what lets Laravel see (and re-issue) the session cookie alongside XSRF-TOKEN.
  // The RESPONSE body is empty and irrelevant; the value arrives as a cookie.
  await fetch(`${API_ORIGIN}/sanctum/csrf-cookie`, {
    credentials: 'include',
    cache: 'no-store',
  });

  const xsrfToken = readCookie('XSRF-TOKEN');
  if (xsrfToken === null) {
    // No class is invented here. The cookie being absent after a 200 is either a third-party-cookie
    // block or a misconfigured `sanctum.stateful` — neither is one of the 18, so it is `null`:
    // unknown, and unknown is permanently non-retryable.
    throw new KbError(
      null,
      false,
      null,
      null,
      'XSRF-TOKEN cookie absent after GET /sanctum/csrf-cookie — check SESSION_DOMAIN and sanctum.stateful',
    );
  }
  return xsrfToken;
}

/**
 * Read one cookie by EXACT NAME and URL-DECODE it.
 *
 * Both halves are load-bearing and both have silent failure modes:
 *
 *  - EXACT NAME, never `document.cookie.includes('XSRF-TOKEN')`. A substring test also matches a
 *    cookie the server never set — `XSRF-TOKEN_backup`, or a host-page cookie named
 *    `MY-XSRF-TOKEN` — and returns a value Laravel will reject as a mismatch. The same parse, and
 *    the same reason, is written out in apps/widget/src/app/resume.ts.
 *  - DECODE. Laravel URL-encodes the cookie, so the base64 padding arrives as `%3D`. Echoing the
 *    raw value as `X-XSRF-TOKEN` produces a 419 on every mutation while the cookie is perfectly
 *    valid — and it reproduces nowhere, because a token whose padding happens to be absent works.
 */
function readCookie(name: string): string | null {
  if (typeof document === 'undefined') return null;

  for (const pair of document.cookie.split('; ')) {
    const eq = pair.indexOf('=');
    if (eq === -1) continue;
    if (pair.slice(0, eq) !== name) continue;
    return decodeURIComponent(pair.slice(eq + 1));
  }
  return null;
}
