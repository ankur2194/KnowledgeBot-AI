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
  // `const`, not `let`: there is exactly one attempt now. These were reassignable because the deleted
  // 419 branch refreshed the credential and re-sent — see the note below for why nothing replaces it.
  const credential = request.credential;
  const response = await send(request, credential);

  // ── THERE IS NO 419 BRANCH HERE, AND ITS ABSENCE IS THE CORRECTION ─────────────────────────────
  //
  // This function used to open with `if (response.status === 419 && credential.kind === 'session')`,
  // refresh the CSRF token, retry once, and bounce to /login on a second 419. **That code could never
  // run against this API.** Laravel's `Handler::render()` calls `prepareException()` — which converts
  // `TokenMismatchException` into `HttpException(419)` — BEFORE `renderViaCallbacks()`, and our render
  // closure in `bootstrap/app.php` maps `$httpStatus === 419` onto `['authentication', 401]`. So a CSRF
  // failure reaches this client as a **401**, and `response.status === 419` is dead for every path.
  //
  // The specs did not catch it because they fabricated 419 responses through MSW and asserted the
  // branch handled them; the premise in their own docblock — "an idle admin gets 419 and never sees a
  // 401" — had the middleware ordering right and the rendered status wrong.
  //
  // ── WHY THE FIX IS DELETION AND NOT THE SAME LOGIC MOVED ONTO 401 ──────────────────────────────
  //
  // Because a 401 CANNOT BE SAFELY RETRIED HERE. The envelope carries the error CLASS, not the status,
  // so the client cannot tell a CSRF rejection (which happens before the action runs, so a retry is
  // free) from an authentication failure (which may not). Retrying a mutation on that guess is a
  // double-submit on every endpoint without an `Idempotency-Key` — which is all of them on this
  // surface, and includes inviting a stranger and mailing them a live capability.
  //
  // So a 401 is thrown, `features/auth/session.ts` turns `authentication` into `{status:'anonymous'}`,
  // and the SPA signs the user out. The cost is the narrow case of a live session whose XSRF-TOKEN
  // cookie went stale on its own: that user is signed out rather than silently recovered. Two things
  // keep the window small — `sessionCredential()` re-reads the cookie on every request rather than
  // caching it, and the login form calls `refreshCsrfToken()` unconditionally before its POST, so the
  // recovery path a user actually takes works. Closing it properly needs a safe-to-repeat signal from
  // the server (a distinct error class for CSRF, or an idempotency key), which is a contract change and
  // is recorded as open rather than guessed at here.
  if (!response.ok) throw await toKbError(response);

  // 204/205 carry no body by definition and `json()` on one rejects with a SyntaxError that would
  // surface as an unparseable `unknown` failure over a request that actually succeeded.
  if (response.status === 204 || response.status === 205) return undefined as T;

  return (await response.json()) as T;
}

/**
 * EVERY SUCCESS BODY ON THIS API IS WRAPPED IN `data`, and the wrapper is not decoration:
 * `App\Support\Contracts\ResponseShape` maps a response KEY to a schema class, so an unwrapped body
 * is literally unpublishable by `php artisan kb:dump-openapi` — and the two endpoints that predate
 * all auth work already wrap. `tests/msw/handlers.ts:52-69` is the fixture-side statement of the
 * same fact and `tests/components/msw-harness.test.tsx:33-39` asserts it.
 */
export interface ApiEnvelope<T> {
  readonly data: T;
}

/**
 * THE UNWRAP, IN ONE PLACE. Every call that expects an envelope goes through here, so `data` is read
 * exactly once — at the fetch boundary — and never by reaching into `.data` at a render site. A
 * render site that knows about the envelope is a render site that has to be edited when the envelope
 * changes, and there are more of those than there are fetchers.
 *
 * IT LIVES HERE, BESIDE `browserFetch`, AND THAT IS THE RECORD OF A MOVE RATHER THAN AN ORIGIN. It
 * was written in `features/auth/session.ts` while auth was the only feature that unwrapped anything,
 * with a standing instruction in this docblock: when a second feature needs it, MOVE it rather than
 * copy it — a second unwrap is a second place the envelope is known. `features/members` and then
 * `features/providers` were that second feature, so the move was made and no re-export shim was left
 * behind in `session.ts`. A single home is the whole point; anyone tempted to add a local
 * `const body = await browserFetch<{data: T}>(…)` in a feature is re-forking it.
 */
export async function browserFetchData<T>(request: BrowserRequest): Promise<T> {
  const body = await browserFetch<ApiEnvelope<T>>(request);
  return body.data;
}

/**
 * A request that carries NO CREDENTIAL AT ALL, for the two `sdk/v1` routes.
 *
 * ── WHY THIS IS NOT A THIRD MEMBER OF THE `Credential` UNION ────────────────────────────────────
 * `Credential` is a union of things that PROVE something, and `streamAnswer` branches on it with an
 * `if/else` — session gets the cookie plus `X-XSRF-TOKEN`, everything else gets a bearer. A third
 * `{kind: 'anonymous'}` member would fall into the else arm and send `Authorization: Bearer
 * undefined`, which is a 401 whose cause is invisible in the diff that introduced it. Worse, it
 * would make "send a chat message with no credential" a type-checkable expression, and the runtime
 * surface has exactly one credential by design.
 *
 * So the union stays closed at two and the credential-less case is its own function. `send()` below
 * takes `Credential | null`, so there is still ONE request builder and one place `cache: 'no-store'`
 * and the error envelope are handled.
 *
 * ── WHAT AUTHORIZES THESE REQUESTS INSTEAD: THE `Origin` HEADER, WHICH WE CANNOT SET ────────────
 * `POST /sdk/v1/bootstrap` and `POST /sdk/v1/session` are origin-validated: Laravel compares the
 * REQUEST's `Origin` header, byte for byte, against the bot's ACTIVE `bot_domains` rows
 * (`BotDomainMatcher`, `hash_equals`, no wildcard grammar, no substring form). The browser sets that
 * header on a cross-origin POST and JavaScript cannot forge it — which is the entire security value
 * of the handshake, and the reason the bot id travels in the BODY where page script may put it.
 *
 * `credentials: 'omit'` is therefore not an omission but a control, exactly as it is on the
 * `chat_session` arm: these routes sit outside `api/*`, they must not acquire the admin session
 * stack, and a cookie sent here would be one the server would have to decide to ignore.
 *
 * EVERY REJECTION IS A BYTE-IDENTICAL 404 — unknown bot id, a bot in another organization, a bot
 * that is not live, an absent `Origin`, `Origin: null`, an unlisted origin — so a caller must not
 * try to tell them apart and must not write copy that guesses which one happened.
 */
export type PublicBrowserRequest = Omit<BrowserRequest, 'credential'>;

export async function browserFetchPublic<T>(request: PublicBrowserRequest): Promise<T> {
  const response = await send(request, null);
  if (!response.ok) throw await toKbError(response);
  if (response.status === 204 || response.status === 205) return undefined as T;
  return (await response.json()) as T;
}

/** The `data` unwrap, for the same reason `browserFetchData` exists: read the envelope once, at the
 *  fetch boundary, never at a render site. */
export async function browserFetchPublicData<T>(request: PublicBrowserRequest): Promise<T> {
  const body = await browserFetchPublic<ApiEnvelope<T>>(request);
  return body.data;
}

/**
 * One request, built from the credential union and nothing ambient.
 *
 * `Credential | null` — `null` is the unauthenticated `sdk/v1` case and NOT a default. It exists so
 * there is one request builder rather than two; see `browserFetchPublic`.
 */
function send(
  request: PublicBrowserRequest,
  credential: Credential | null,
): Promise<Response> {
  const headers: Record<string, string> = { Accept: 'application/json' };

  if (request.body !== undefined) headers['Content-Type'] = 'application/json';
  if (request.idempotency_key !== undefined) headers['Idempotency-Key'] = request.idempotency_key;

  // THE WHOLE DESIGN IS THIS SWITCH. `credentials` is decided by the credential, never by the
  // surface the code happens to be running on.
  let credentials: RequestCredentials;
  if (credential === null) {
    // The `sdk/v1` handshake. No cookie, no bearer, and 'omit' rather than 'same-origin': the API is
    // a different origin from both surfaces, so 'same-origin' would send nothing anyway and would
    // start sending something the day the hostnames collapse in a development environment. What
    // authorizes this request is the `Origin` header, which the browser sets and we cannot.
    credentials = 'omit';
  } else if (credential.kind === 'session') {
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
 * The `Credential` for a session-authenticated call, with no round trip on the common path.
 *
 * The union above demands `xsrf_token` for `kind: 'session'` even on a GET, and `readCookie` is
 * module-private for the reason its own docblock gives — so this lives HERE, reusing that parse,
 * rather than in `features/auth/` re-implementing it. A second `readCookie` is the exact fork the
 * next docblock warns about: two parses, one of them eventually missing the `decodeURIComponent`,
 * and a 419 on every mutation while the cookie is perfectly valid.
 *
 * Laravel has already set `XSRF-TOKEN` on any document that has talked to it, so the usual path is
 * zero requests. A cold document (first paint after a deploy, a hard reload with cleared storage)
 * pays exactly one `GET /sanctum/csrf-cookie`.
 *
 * NOT for the login POST. Login is the one mutation guaranteed to run on a document that may never
 * have had the cookie, and `refreshCsrfToken()` is the only thing that turns the realistic
 * misconfiguration (blocked third-party cookie, wrong SESSION_DOMAIN, host missing from
 * `sanctum.stateful`) into a diagnosable error BEFORE the POST instead of an opaque 419 after it. So
 * that call site refreshes unconditionally; see src/features/auth/login-form.tsx.
 */
export async function sessionCredential(): Promise<Credential> {
  const existing = readCookie('XSRF-TOKEN');
  return { kind: 'session', xsrf_token: existing ?? (await refreshCsrfToken()) };
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
