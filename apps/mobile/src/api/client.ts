import { KbError, toKbError } from '@kb/contracts';

import { readToken } from '@/auth/secure-store';
import { API_ORIGIN } from '@/lib/env';

/**
 * The ONE Laravel caller, and the ONE module that reads the bearer token.
 *
 * Two things live here and nowhere else, and both are here for the same reason — they are only
 * correct if there is a single place to check them:
 *
 *  1. ATTACHING THE TOKEN. `readToken()` returns null once the token is inside the 90-second
 *     expiry grace window, so a request cannot be sent with a token that is about to die
 *     mid-flight. See src/auth/expiry.ts for why 90 s.
 *  2. HANDLING A 401. A 401 means the token is GONE — expired, or revoked from another device via
 *     the settings device list. It is never a transient failure and it is never retried: the class
 *     is `authentication`, which is non-retryable in the taxonomy, and a retry loop against a dead
 *     token is how a device gets rate-limited out of its own login endpoint.
 *
 * This app talks to Laravel and only to Laravel. There is no FastAPI host here, no internal path
 * prefix, and no HMAC signing key — CI greps this directory for all three. A device that could
 * sign an internal request would be a per-device copy of a service credential, and every
 * authorization, quota and rate-limit check lives on the other side of that seam.
 */

/**
 * Notified whenever a request comes back 401 / `authentication`, exactly once per occurrence.
 *
 * Registered by src/auth/session-provider.tsx. It is a subscription rather than a direct import of
 * the session module because the dependency has to run this way round: the session provider knows
 * about the API client, and the API client must not know about React.
 *
 * The handler's contract, in order: purge SecureStore, REPLACE the QueryClient (not `clear()` —
 * that keeps the same observers, which immediately refetch), keep any composed draft in memory,
 * and navigate to login. The draft is restored after re-auth. Losing a paragraph the user just
 * typed is the part everyone forgets and the part they notice.
 */
type UnauthenticatedHandler = () => void;
let onUnauthenticated: UnauthenticatedHandler | null = null;

export function setUnauthenticatedHandler(handler: UnauthenticatedHandler | null): void {
  onUnauthenticated = handler;
}

export interface ApiRequest {
  /**
   * Path only, with a leading slash, e.g. `/rt/v1/conversations`.
   *
   * `rt/v1`, NOT `api/v1`. `api/v1` is the ADMIN group in services/core-api/bootstrap/app.php —
   * Laravel's `api` middleware with statefulApi()'s cookie session and CSRF in front of it. This
   * app sends a Sanctum personal access token in an Authorization header and never a cookie, and
   * routes/api_public.php names mobile as exactly the exception that carries a PAT on the PUBLIC
   * runtime surface. A path under `api/v1` from here aims a bearer at the one group designed to
   * reject bearers.
   *
   * The origin comes from EXPO_PUBLIC_API_ORIGIN and cannot be overridden at runtime.
   */
  readonly path: string;
  readonly method?: 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE';
  readonly body?: unknown;
  /** Forwarded to fetch so `queryClient.cancelQueries()` aborts the request and not merely its
   *  result. A queryFn that drops this makes cancelQueries a no-op. */
  readonly signal?: AbortSignal;
  /** Mutations that must survive a retry carry one. A mutation WITHOUT a key must never be
   *  retried by anything, including a double-tap — disable the control on isPending. */
  readonly idempotencyKey?: string;
}

/**
 * Builds the header set for an authenticated request, or returns null when there is no usable
 * token. The only caller that legitimately needs the raw token separately is the streaming path,
 * which cannot go through `apiFetch` because it reads a body incrementally — see
 * `getBearerToken()` below.
 */
async function authHeaders(): Promise<Record<string, string> | null> {
  const token = await readToken();
  if (token === null) return null;
  return {
    Authorization: `Bearer ${token}`,
    Accept: 'application/json',
  };
}

/**
 * The streaming send needs the token itself, because it issues its own `expo/fetch` call with an
 * `Accept: text/event-stream` and reads `response.body`. Exported from HERE rather than from the
 * auth module so the "one module reads the token" rule survives: src/features/chat/stream-answer.ts
 * asks the API client for a bearer, it does not open the keychain.
 *
 * Returns null when the token is absent or inside the expiry grace window. The caller must route
 * to login BEFORE the POST rather than sending and handling the 401, so the composed message is
 * not lost to a round trip.
 */
export async function getBearerToken(): Promise<string | null> {
  return readToken();
}

/**
 * `toKbError` — the non-2xx-response-to-`KbError` mapping — USED TO BE DECLARED HERE and is now
 * imported from `@kb/contracts`, where `apps/web` needed the identical logic and lifted it.
 *
 * The two copies were the same function, and the divergence they invited has no visible symptom:
 * `retry_after` comes off the `Retry-After` RESPONSE HEADER rather than out of the JSON envelope, so
 * an app that drops it retries inside the window it was told to wait, forever, with nothing logged.
 * The shared `ErrorResponseLike` is structural rather than a nominal `Response` for this app's sake
 * specifically — `expo/fetch` returns its own WinterCG response type, so a nominal parameter would
 * force a cast at the one call site that matters most, the streaming path. It fits unchanged.
 *
 * It is re-exported here rather than only imported, because every module in this app that needs it
 * already imports from this file and there is no reason for a second import path to the same thing.
 */
export { toKbError };
export type { ErrorResponseLike } from '@kb/contracts';

/**
 * Called on every 401 and on every `error_class: 'authentication'`, from both the REST path and
 * the streaming path. Idempotent: two racing requests that both 401 must produce one purge and one
 * navigation, not two.
 */
export function handleAuthenticationFailure(): void {
  onUnauthenticated?.();
}

/**
 * THE OFFLINE SIGNAL, AND WHY IT IS NOT A `KbError`.
 *
 * A phone loses the network for real — a lift, a tunnel, airplane mode, a train. That is the one
 * failure this client has that the browser and the widget effectively do not, and it is not a
 * server error: no request was ever answered, so there is no envelope, no `error_class`, no
 * `request_id` and nothing for a support engineer to grep. The taxonomy has 18 classes and none of
 * them means "the radio is off"; `internal_dependency` is the one that looks closest and is exactly
 * wrong, because it is retryable AND it pages — a subway ride would wake someone up.
 *
 * So it is a SEPARATE TYPE rather than a 19th class or a `KbError` with a null class:
 *
 *  - a null-class `KbError` is "unknown, and unknown is permanent", which is a different and worse
 *    statement than "come back when you have signal";
 *  - the retry predicate in src/lib/query-client.ts tests `instanceof KbError` and returns false
 *    for everything else, so an OfflineError is never retried into a dead radio on a metered
 *    connection, which is the behaviour we want from it;
 *  - `networkMode: 'offlineFirst'` pauses and resumes these at the TanStack Query layer, and
 *    "you are offline" is a different sentence with a different remedy than "something went wrong
 *    on our side".
 *
 * `cause` keeps the platform's own `TypeError` for logging. It is never rendered.
 */
export class OfflineError extends Error {
  constructor(cause?: unknown) {
    super('offline: the request never reached the network');
    this.name = 'OfflineError';
    this.cause = cause;
  }
}

/**
 * The transport-failure classifier, in ONE place because both callers must agree.
 *
 * A `fetch` that never got a response rejects with a `TypeError` on every runtime we ship on:
 * `TypeError: Network request failed` on React Native, `TypeError: fetch failed` on Node and in
 * expo/fetch. That is the offline shape. An `AbortError` is NOT a failure at all — it is
 * cancellation, an outcome (499 / `user_cancellation`) — and it is filtered out before this is
 * reached, by name rather than by type.
 *
 * NARROWING NOTE, because `TypeError` is also what a genuine programming mistake throws: this is
 * only ever applied to the rejection of the `fetch` call itself, never around application code. A
 * `TypeError` raised inside a read loop or a reducer must stay a bug and must not be reported to a
 * user as "you are offline".
 */
export function asOfflineError(error: unknown): OfflineError | null {
  if (error instanceof OfflineError) return error;
  if (error instanceof TypeError) return new OfflineError(error);
  return null;
}

/**
 * Resolves with the parsed JSON body, or REJECTS with a `KbError` — or with an `OfflineError` when
 * the request never reached the network.
 *
 * THE ORDER BELOW IS THE CONTRACT. Every step exists because the step before it would otherwise
 * produce a plausible wrong answer:
 *
 *  1. Read the token FIRST. `readToken()` returns null inside the 90-second expiry grace window, so
 *     a request is never sent with a token that would die mid-flight; the user is routed to login
 *     with their composed text still in memory instead of losing it to a round trip.
 *  2. `fetch`. A rejection here is a TRANSPORT failure — offline — and is classified by
 *     `asOfflineError`, never mapped into the taxonomy.
 *  3. 401 BEFORE `res.ok`. A 401 means the token is gone (expired, or revoked from another device),
 *     which is a session event and not merely a failed request: it purges and navigates. Handling
 *     it inside the generic `!res.ok` branch would throw the right error and skip the purge.
 *  4. Every other non-2xx through `toKbError`, which reads the envelope and takes `Retry-After` off
 *     the response HEADER.
 *  5. Only then the body.
 *
 * This function names NO endpoint. `path` is a parameter, so there is exactly one place that
 * concatenates an origin and exactly one place that attaches a credential.
 */
export async function apiFetch<T>(request: ApiRequest): Promise<T> {
  const headers = await authHeaders();
  if (headers === null) {
    // Deliberately NOT a request that we let 401. There is no usable token, so a send would spend a
    // round trip to learn what is already known, and on a metered radio at that. `authentication`
    // is non-retryable, so nothing above will loop on this.
    handleAuthenticationFailure();
    throw new KbError('authentication', false, null, null, 'no usable token on this device');
  }

  const requestHeaders: Record<string, string> = { ...headers };

  // The IETF `Idempotency-Key`, i.e. the PUBLIC spelling. `X-KB-Idempotency-Key` is the
  // Laravel<->FastAPI seam's header and a client must never send it: this app has no HMAC key, so
  // anything it wrote there would be an unsigned claim on an internal contract.
  if (request.idempotencyKey !== undefined) {
    requestHeaders['Idempotency-Key'] = request.idempotencyKey;
  }

  let body: string | undefined;
  if (request.body !== undefined) {
    body = JSON.stringify(request.body);
    requestHeaders['Content-Type'] = 'application/json';
  }

  let response: Response;
  try {
    response = await fetch(`${API_ORIGIN}${request.path}`, {
      method: request.method ?? 'GET',
      headers: requestHeaders,
      body,
      // Forwarded so `queryClient.cancelQueries()` aborts the REQUEST and not merely its result.
      // Dropped, it leaves a request that outlives its screen and finishes over cellular.
      signal: request.signal,
    });
  } catch (error) {
    // Cancellation is an outcome, not a failure, and it is identified by NAME: an AbortError is a
    // DOMException on some runtimes and a plain Error on others, so `instanceof` is not portable.
    if (error instanceof Error && error.name === 'AbortError') throw error;
    const offline = asOfflineError(error);
    if (offline !== null) throw offline;
    throw error;
  }

  if (response.status === 401) {
    handleAuthenticationFailure();
    throw await toKbError(response);
  }
  if (!response.ok) throw await toKbError(response);

  // A 204 has NO BODY, and `res.json()` on an empty body throws a SyntaxError — which would be
  // reported as a malformed server response for the one status that means "this worked and there is
  // nothing to say". Every successful delete and most acknowledgements are 204.
  if (response.status === 204) return undefined as T;

  return (await response.json()) as T;
}

/**
 * Login. Mints a Sanctum personal access token with explicit abilities (`chat:send`,
 * `conversations:read` — never `['*']`) and a hard `expires_at`, both decided server-side.
 * Persisting the result is the session provider's job, through src/auth/secure-store.ts.
 *
 * STILL A STUB, AND DELIBERATELY SO. There is no token-mint route anywhere in
 * services/core-api — no `rt/v1` or `api/v1` login endpoint, no `PersonalAccessToken` issuance, no
 * ability catalog to mint against. Everything this function would need is a server-side decision
 * (which abilities, what `expires_at`, which surface the route lives on, what the device-list row
 * looks like), and inventing a request shape here would produce a client whose fixtures are the
 * only source of truth for a contract Laravel has not written — which is exactly the failure mode
 * kb-internal-api-contracts documents for the chat body. Blocked on `control-plane-engineer`.
 */
export function login(_credentials: { email: string; password: string }): Promise<never> {
  throw new Error('not implemented');
}

/**
 * Logout. Calls the revoke endpoint FIRST so the token row is deleted server-side, then purges
 * locally — in that order, because a purge-first logout that then fails the network leaves a live
 * token on the server with no way for this device to name it. A revoke that fails must still purge
 * locally and must still be reported, since the device list is the kill switch that prices the
 * 30-day lifetime.
 *
 * STILL A STUB, for the same reason as `login` above, and the reason was re-verified against
 * services/core-api on 2026-08-11 rather than carried forward: five routes exist in the entire
 * Laravel app and none is a revoke; `rt/v1` is an empty `Route::group` with a TODO; `User` does not
 * `use HasApiTokens`; and none of the six migrations creates `personal_access_tokens`. So there is
 * not merely no revoke route — there is no token row for one to delete, because nothing mints one.
 *
 * The LOCAL half of logout is fully implemented and does not depend on this —
 * src/auth/session-provider.tsx's `signOut` calls this inside a `try`, swallows the failure, and
 * purges SecureStore, the QueryClient and the on-disk transcript cache regardless.
 *
 * READ THE GAP AS CONDITIONAL, NOT PRESENT. Today a sign-out is locally complete and server-side
 * absent, and no token survives it because none was ever issued. The day a mint route lands without
 * a revoke route beside it, every sign-out from then on leaves a live token on the server until its
 * `expires_at` — 30 days for mobile per config/sanctum.php:39 — with no device list to kill it,
 * because that does not exist either. The mint and the revoke belong in the same change.
 * Blocked on `control-plane-engineer`; see the long note at `signOut`.
 */
export function revokeCurrentToken(): Promise<void> {
  throw new Error('not implemented');
}
