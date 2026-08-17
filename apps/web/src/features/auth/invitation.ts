import { KbError, type InvitationPreview, type SessionResource } from '@kb/contracts';
import { queryOptions } from '@tanstack/react-query';

import { sessionCredential } from '@/lib/api/browser';

import { browserFetchData } from './session';

/**
 * The invitation flow's transport, cache key and copy — everything the register screen and the accept
 * screen both need, in one JSX-free module so `tests/unit/**` can reach it (the `unit` Vitest project
 * runs in `node` with no JSX transform; see session.ts's header).
 *
 * ── THE TOKEN TRAVELS IN A POST BODY, NEVER IN A PATH SEGMENT ─────────────────────────────────────
 * All three endpoints below are POSTs, and the preview is a POST even though it is a READ. The token
 * is a 256-bit bearer capability, and a capability in a URL lands in Traefik's access log, in the
 * `Referer` header of every outbound navigation, and in the browser's history
 * (laravel-sanctum-auth NN4). `routes/api_auth.php` mounts all three with no `{token}` segment, so
 * there is no path shape for a lookalike route to collide with either.
 *
 * The SPA route still has to receive it somehow, and an email can only carry a URL — so it arrives as
 * `?token=…`, is read once by the server page, and is stripped from the address bar on mount by
 * `useStripTokenFromUrl`. One load in history, not a permanent entry.
 */

/** `POST {token}` -> 200 `{data: InvitationPreview}` | 404. `throttle:invitation`. */
export const INVITATION_PREVIEW_PATH = '/api/v1/auth/invitations/preview';

/**
 * Where a completed registration and a completed acceptance both land.
 *
 * The overview, not `/settings` and not a "welcome" route: registration and acceptance both end with
 * a session whose `current_organization_id` the server has just set, and the overview is the one place
 * that means something without knowing what the user came to do.
 *
 * Both navigations use `browserNavigation.replace`, not `assign`. See the call sites — the entry being
 * overwritten is the one that arrived holding `?token=…`.
 */
export const CONSOLE_ROUTE = '/';

/** `POST {token,name,password,password_confirmation}` -> 201 `{data: SessionResource}` | 422. */
export const REGISTER_PATH = '/api/v1/auth/register';

/** `POST {token}` -> 200 `{data: SessionResource}` | 422. AUTHENTICATED (`auth:sanctum`). */
export const ACCEPT_INVITATION_PATH = '/api/v1/auth/invitations/accept';

/**
 * `singleToken` USED TO LIVE HERE AND DELIBERATELY DOES NOT ANY MORE — it is
 * `src/lib/auth/single-token.ts`, dependency-free, for the reason that file's docblock gives:
 *
 * THIS MODULE IS CLIENT-ONLY, TRANSITIVELY, AND ONLY A `'use client'` COMPONENT MAY IMPORT IT. It imports
 * `browserFetchData` from `./session`, and `session.ts` also declares the React context
 * `<SessionProvider>` consumes — so a SERVER COMPONENT importing anything at all from here drags
 * `createContext` into the React Server Component graph and Turbopack refuses the build:
 * "You're importing a module that depends on `createContext` into a React Server Component module."
 *
 * It is caught by `pnpm web:build` ONLY. `web:typecheck` and `web:test` both pass while it is broken,
 * because neither compiles the RSC graph — which is why the two server pages in this flow import their
 * token helper from `lib/` and nothing else from `features/auth/`.
 */

/**
 * THE ONE SENTENCE FOR ALL FIVE INVALID STATES, and every word of it is chosen for what it does NOT
 * say.
 *
 * `App\Services\Auth\RegistrationService::INVALID_INVITATION` is one byte-identical 422 covering
 * unknown, expired, already accepted, revoked, AND a pending invitation into a suspended
 * organization; `InvitationPreviewController` collapses the same five into one 404 with the shared
 * deny body. The states are not distinguishable on the wire ON PURPOSE — `App\Enums\InvitationStatus`
 * derives them from three timestamps precisely so a prober holding a guessed token cannot learn
 * whether it was ever real. "This invitation has expired" would be false for four of the five and
 * would confirm the token existed; "already used" would confirm it harder.
 *
 * So the copy makes no claim about WHICH state, and its remedy is the same in all five: ask for a new
 * one. The wording deliberately matches the server's own string, so a guest who is refused by the
 * preview's 404 and a guest who is refused by register's 422 read the same sentence.
 */
export const INVALID_INVITATION_COPY =
  'This invitation is no longer valid. Ask an organization owner to send you a new one.';

/**
 * Does this failure mean "the token is not good", as opposed to "something broke"?
 *
 * BRANCHED ON `error_class`, NEVER ON THE STATUS. `bootstrap/app.php:282` maps a 404 to
 * `['authorization', 404]`, so the preview's deny arrives as `error_class: 'authorization'` — the
 * same class a 403 carries elsewhere, which is exactly why the status is the wrong variable to read.
 *
 * It is safe to treat `authorization` as "bad token" ON THIS ENDPOINT ONLY: the preview route is a
 * guest route with no policy and no organization binding, so the sole `authorization`-shaped outcome
 * reachable there is `previewable()`'s 404. Every other class — a 429 from `throttle:invitation`, a
 * 503, an unparsed envelope — is a genuine fault and gets its own class-mapped sentence, because
 * telling somebody their invitation is dead when our database was briefly unreachable is a lie that
 * costs them the invitation.
 */
export const isInvalidInvitationError = (error: unknown): boolean =>
  error instanceof KbError && error.error_class === 'authorization';

/**
 * `['invitation-preview', token]` — NOT org-prefixed, and that is not the exception `SESSION_KEY` is.
 *
 * There is no organization to prefix with. The holder of an invitation is by definition not yet a
 * member of the organization it names, `InvitationPreview` deliberately carries no organization id
 * (an id would let a token holder address a tenant they are not in), and `useOrgKey()` would throw
 * here because `useCurrentOrgId()` is null on every guest document. The token IS the scope, on both
 * sides of the wire.
 *
 * THE TOKEN IS IN THE KEY, and the alternative was worse. A token-less `['invitation-preview']` is one
 * cache entry for two capabilities: follow a second invitation link in the same document and the
 * screen renders the first invitation's organization and address with no request made. Keys live in
 * the QueryClient's heap only — `providers.tsx` forbids every persister, so nothing here reaches
 * localStorage, a devtools panel in production, or a disk — which is the property that makes carrying
 * a capability in a cache key acceptable at all.
 */
export const invitationPreviewKey = (token: string) => ['invitation-preview', token] as const;

/**
 * `staleTime: Infinity` because an invitation preview is an answer about an IMMUTABLE capability: the
 * organization's name, the pinned address and the granted role cannot change under a token, and when
 * the token dies the answer is a 404 rather than a different body. The limiter is the sharper reason —
 * `throttle:invitation` is keyed on the TOKEN, so a remount that re-POSTs spends the guest's own
 * budget and can lock them out of the screen they are standing on.
 *
 * NO `retry` PROPERTY: the identifier is an ESLint error outside src/lib/query/client.ts, and the
 * global predicate there is already correct here — `authorization` is not retryable by class, and a
 * 429 with a `Retry-After` gets exactly one attempt after the header's window.
 *
 * `enabled` on a non-empty token, so a visitor who arrives at `/register` with no `?token=` at all
 * makes NO request. An empty token would 404 anyway, but it would 404 against the token-keyed limiter
 * and teach the screen nothing it does not already know from the empty string.
 */
export const invitationPreviewQueryOptions = (token: string) =>
  queryOptions({
    queryKey: invitationPreviewKey(token),
    queryFn: async ({ signal }) =>
      browserFetchData<InvitationPreview>({
        path: INVITATION_PREVIEW_PATH,
        method: 'POST',
        body: { token },
        // The LAZY credential, not login's unconditional `refreshCsrfToken()`. Laravel guards
        // non-read methods with PreventRequestForgery, so this POST needs `X-XSRF-TOKEN` even though
        // it reads — and on a cold guest document `sessionCredential()` pays exactly one
        // `GET /sanctum/csrf-cookie` to get it. What login's unconditional refresh buys is
        // DIAGNOSABILITY before a POST that would otherwise 419; here that is already bought, because
        // this request runs before the form exists and a blocked cookie surfaces as this screen's own
        // error with nothing typed yet to lose.
        credential: await sessionCredential(),
        signal,
      }),
    enabled: token.length > 0,
    staleTime: Number.POSITIVE_INFINITY,
  });

/** `POST /api/v1/auth/register` — 201 with the SessionResource of the user it just signed in. */
export const registerWithInvitation = async (values: {
  readonly token: string;
  readonly name: string;
  readonly password: string;
  readonly password_confirmation: string;
}): Promise<SessionResource> =>
  browserFetchData<SessionResource>({
    path: REGISTER_PATH,
    method: 'POST',
    body: values,
    credential: await sessionCredential(),
  });

/** `POST /api/v1/auth/invitations/accept` — authenticated; 200 with the re-scoped session. */
export const acceptInvitation = async (token: string): Promise<SessionResource> =>
  browserFetchData<SessionResource>({
    path: ACCEPT_INVITATION_PATH,
    method: 'POST',
    body: { token },
    credential: await sessionCredential(),
  });

/**
 * `REGISTER_KNOWN_PATHS` HAS MOVED to `./known-paths.ts`, beside `LOGIN_KNOWN_PATHS`, and it is now
 * derived from `rules/RegisterRequest.json` rather than from `registerSchema.shape` — the manifest is
 * the server's own field vocabulary, and `applyServerErrors` partitions the server's 422 keys against
 * it. Its docblock there carries the two reasons the subtraction matters (`token` is hidden, and
 * `email` is absent by design). `register-form.tsx` imports it from that module.
 */
