import {
  KbError,
  type SessionMembership,
  type SessionResource,
  type SessionUser,
} from '@kb/contracts';
import { queryOptions } from '@tanstack/react-query';

import { browserFetch, sessionCredential, type BrowserRequest } from '@/lib/api/browser';

/**
 * The session layer — the ONE answer to "who am I, and which organization am I acting in", which are
 * the two questions that are not in the URL.
 *
 * ── NO JSX IN THIS FILE, AND THAT IS A HARD CONSTRAINT RATHER THAN A PREFERENCE ──────────────────
 * The `unit` Vitest project runs in `node` and installs NO react plugin; `tsconfig.json` sets
 * `jsx: "preserve"` because Next owns that transform, so esbuild leaves JSX verbatim and Vite fails
 * the whole FILE with "content contains invalid JS syntax" — measured. Any module a `tests/unit/**`
 * spec reaches, transitively, must therefore be JSX-free, and both `session-query.test.ts` and
 * `switch-organization.test.ts` reach this one.
 *
 * ── AND NO `react` IMPORT EITHER — A CONVENTION, NOT AN ENFORCED CONSTRAINT (D38, corrected) ─────
 * The context and its three hooks used to live here; they are now in `session-context.ts`, so this
 * module imports nothing from the `react` package and a server component can import `SESSION_KEY`,
 * `ME_PATH` or `activeOrganizations` without dragging the client runtime into its graph.
 *
 * BE PRECISE ABOUT WHAT THAT BUYS, BECAUSE THE ORIGINAL JUSTIFICATION WAS WRONG. D38 recorded that a
 * server module importing anything from this file "broke the RSC compile" because the chain reached
 * `createContext`. That was tested directly and it is FALSE in this Next version: a server page
 * importing a module that calls `createContext` at module scope compiles clean, and so does one that
 * imports a hook and calls it (the pages here are all dynamic, so nothing prerenders and no hook ever
 * runs at build time). Both probes were run and both compiled. Whatever went red for the agent that
 * extracted `src/lib/auth/single-token.ts` was therefore something else in that tree, and it could not
 * be reproduced afterwards — so the mechanism is recorded as unknown rather than restated.
 *
 * So the split is SEPARATION OF CONCERNS, and its value is that a reader can tell which half is safe
 * where. `pnpm web:build` does NOT enforce it. Anyone re-coupling these should not expect a red build
 * to catch them, which is exactly why it is written down here.
 *
 * `queryOptions` from `@tanstack/react-query` stays and is fine: a pure identity helper with no
 * client-only API, and prefetching from a server component with it is the library's documented pattern.
 *
 * There is no `'use client'` directive, and that is now a true statement rather than an assumption:
 * nothing in this file touches a client-only API.
 */

/**
 * `['session']` — DELIBERATELY NOT ORG-PREFIXED, and this is the ONE legal exception to
 * tanstack-query-table NN1 ("every query key begins ['org', orgId, …]", src/lib/query/client.ts:82-92).
 *
 * The reason is circularity, not convenience. `orgKey(orgId, …)` REQUIRES an `orgId`, and this query
 * is the PRODUCER of that value: the current organization lives in the session cookie and this
 * response is the only place the browser can read it from. Namespacing the identity document by the
 * organization it announces would mean deriving the key from the answer the key is fetching.
 *
 * It is also not tenant data in the leaking sense. The resource is scoped to the USER — their own
 * name, their own membership list — and a user seeing their own memberships across an org switch is
 * not a cross-tenant serve. Every OTHER query in this app reads `useOrgKey()` and is therefore
 * prefixed, and this one is the value that makes that possible.
 *
 * What still has to be true: an org switch must DESTROY this entry rather than refetch it, which
 * `useResetQueryClient()` does by replacing the whole client (src/components/providers.tsx:9-18).
 * A prefix is not what invalidates it, so not having one costs nothing.
 */
export const SESSION_KEY = ['session'] as const;

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
 * THE UNWRAP, IN ONE PLACE. Every auth call goes through here, so `data` is read exactly once — at
 * the fetch boundary — and never by reaching into `.data` at a render site. A render site that knows
 * about the envelope is a render site that has to be edited when the envelope changes, and there are
 * more of those than there are fetchers.
 *
 * It lives beside the session rather than beside `browserFetch` for a scope reason and not a design
 * one: this batch may add exactly `sessionCredential()` to `src/lib/api/browser.ts`. When a second
 * feature needs it, MOVE it there rather than copying it — a second unwrap is a second place the
 * envelope is known.
 */
export async function browserFetchData<T>(request: BrowserRequest): Promise<T> {
  const body = await browserFetch<ApiEnvelope<T>>(request);
  return body.data;
}

/**
 * The four auth endpoints, as the approved route table names them (decision D1). Spelled once each,
 * because a path literal repeated at a call site is a path literal that drifts on rename.
 *
 * Note the METHODS: logout and the org switch are both POST. `DELETE /session` and
 * `PUT /session/organization` were the natural guesses and both are wrong — Laravel's routes are
 * POST, and a wrong verb is a 405 that renders as `error_class: null` (the unknown-class copy) with
 * no clue in it.
 */
export const ME_PATH = '/api/v1/me';
export const LOGIN_PATH = '/api/v1/auth/login';
export const LOGOUT_PATH = '/api/v1/auth/logout';
export const SWITCH_ORGANIZATION_PATH = '/api/v1/session/organization';

/**
 * `GET /api/v1/me` → 200 `{data: SessionResource}` | 401 envelope (`error_class: 'authentication'`).
 *
 * `signal` is forwarded because `queryClient.cancelQueries()` is a NO-OP against a `queryFn` that
 * drops it (src/lib/api/browser.ts:38-40) — and cancelling in-flight authenticated reads is step 1
 * of both logout and the org switch.
 *
 * 419 cannot happen here: Laravel's `PreventRequestForgery` only guards non-read methods, so a dead
 * session on a GET is a 401. The 419 story is mutations-only.
 */
export const fetchSession = async (signal: AbortSignal): Promise<SessionResource> =>
  browserFetchData<SessionResource>({
    path: ME_PATH,
    credential: await sessionCredential(),
    signal,
  });

/**
 * NO `retry` PROPERTY, and not because the default is acceptable — because the identifier is an
 * ESLint error outside src/lib/query/client.ts (eslint.config.mjs:63-81). The global predicate
 * already refuses to retry `authentication` by class, which is exactly the policy this query wants:
 * a 401 is an answer, not a failure to reattempt.
 */
export const sessionQueryOptions = () =>
  queryOptions({
    queryKey: SESSION_KEY,
    queryFn: ({ signal }) => fetchSession(signal),
  });

/**
 * The memberships a user may actually ACT in.
 *
 * `SessionResource.organizations` carries ALL memberships including `invited` and `suspended` ones,
 * each with its own `status`, because filtering server-side would leave the UI unable to tell "you
 * have no organizations" from "your membership was suspended" — different sentences, different next
 * steps (packages/contracts/src/resources/session.ts:109-115). So the CLIENT filters, here, once.
 * An org switcher that offers a suspended membership offers a 403.
 */
export const activeOrganizations = (
  session: Pick<SessionResource, 'organizations'>,
): readonly SessionMembership[] =>
  session.organizations.filter((organization) => organization.status === 'active');

/**
 * THE ONE NAVIGATION SEAM IN THIS FEATURE, and it exists for a measured reason rather than a
 * stylistic one.
 *
 * Login success, logout and the session-expiry bounce all end in a FULL DOCUMENT navigation, never
 * `router.push`: the Client Router Cache lives in the tab and is keyed by path, so a soft navigation
 * replays RSC payloads captured under the previous identity, and `/login` additionally lives under a
 * different root layout.
 *
 * `window.location.assign` is `[LegacyUnforgeable]`: in Chromium it is an OWN property of the
 * location object with `configurable: false, writable: false` (measured in this harness on
 * 2026-08-13 — `vi.spyOn(window.location, 'assign')` throws `TypeError: Cannot redefine property`).
 * A spec that tries to stub it therefore does not stub it — it NAVIGATES THE TEST PAGE AWAY, which
 * ends the run with an error that names nothing useful. Routing every auth navigation through one
 * ordinary object property is what makes "assert it as a call, never follow it" possible at all.
 *
 * `assign`, NOT `replace`, for login and logout: Back returns to `/login` rather than skipping past
 * it. The single-use-token screens (reset password, invitation) are the opposite case — see
 * `replace` below.
 *
 * Note src/lib/api/browser.ts:71 calls `window.location.assign('/login')` directly on a second 419.
 * That one is not reachable through this seam and is out of this batch's scope; it is why the login
 * form pre-checks CSRF instead of relying on that branch.
 */
export const browserNavigation = {
  assign: (destination: string): void => {
    window.location.assign(destination);
  },
  /**
   * `replace`, for the screens whose URL CARRIED A SINGLE-USE CREDENTIAL.
   *
   * A completed password reset (and an accepted invitation) navigates with `replace` so the entry
   * holding `?token=…` is OVERWRITTEN in the history stack rather than pushed past: after `assign`,
   * one Back press puts a consumed capability back in the address bar, where it is re-readable, and
   * a second forward navigation would re-send it as a `Referer` on any non-same-origin link. The
   * reset form additionally strips the token from the URL with `history.replaceState` on mount, so
   * this is the second of two layers rather than the only one.
   *
   * Same `[LegacyUnforgeable]` problem as `assign`: `window.location.replace` is an OWN property with
   * `configurable: false, writable: false`, so `vi.spyOn(window.location, 'replace')` throws and the
   * spec navigates the harness away instead of observing anything. Which is why this seam exists and
   * why no auth screen calls `window.location.*` directly.
   */
  replace: (destination: string): void => {
    window.location.replace(destination);
  },
};

// ── the state machine ────────────────────────────────────────────────────────────────────────────

/**
 * Four states, and the fourth is the one that is easy to leave out.
 *
 * `unavailable` exists because `anonymous` must mean "Laravel said `authentication`" and NOTHING else.
 * Folding a 502, a `rate_limit` or an `internal_dependency` into `anonymous` would bounce a perfectly
 * signed-in admin to `/login`, losing their place and telling them a lie about why; and leaving those
 * on `loading` renders skeletons forever with no error anywhere. So a failure that is not an identity
 * ANSWER gets its own state and its own class-mapped sentence.
 */
export type SessionState =
  | { readonly status: 'loading' }
  | { readonly status: 'anonymous' }
  | { readonly status: 'unavailable'; readonly error: KbError }
  | {
      readonly status: 'authenticated';
      readonly user: SessionUser;
      /** ALL memberships, `invited` and `suspended` included. Run them through
       *  `activeOrganizations()` before offering one as a choice. */
      readonly organizations: readonly SessionMembership[];
      /** `null` is a real, reachable state — see `useCurrentOrgId`. */
      readonly orgId: string | null;
    };

/** Pure, so the machine is asserted in the `node` project with no renderer at all. */
export function toSessionState(
  status: 'pending' | 'error' | 'success',
  data: SessionResource | undefined,
  error: Error | null,
): SessionState {
  if (status === 'error') {
    // Branch on `error_class`, never on a status code: one class renders different statuses per
    // surface, and a 401 from a proxy that never reached Laravel carries no envelope at all.
    if (error instanceof KbError && error.error_class === 'authentication') {
      return { status: 'anonymous' };
    }
    // No envelope parsed => `error_class: null` => unknown, and unknown is permanently non-retryable.
    // Never invent a class name to fill the slot.
    const kb =
      error instanceof KbError
        ? error
        : new KbError(null, false, null, null, error?.message ?? 'session request failed');
    return { status: 'unavailable', error: kb };
  }

  if (status === 'success' && data !== undefined) {
    return {
      status: 'authenticated',
      user: data.user,
      organizations: data.organizations,
      // Straight carry. The one field the whole org-switching hazard turns on, and it is nullable.
      orgId: data.current_organization_id,
    };
  }

  return { status: 'loading' };
}

// ── the context and its readers LIVE IN session-context.ts ───────────────────────────────────────
//
// `SessionContext`, `useSession`, `useCurrentOrgId` and `useOrgKey` were HERE; moving them out is
// decision D38, and see the header for the correction that came with it — the build failure D38
// blamed on `createContext` does not reproduce, so this separation is a readable boundary rather than
// a fix for a red build. Re-coupling them will NOT turn anything red. That is the reason to write it
// down instead of relying on a gate.

// ── the organization switch, as five ordered steps ───────────────────────────────────────────────

/**
 * The route the switch lands on before anything else happens. `/settings` renders nothing org-scoped,
 * which is the entire property being relied on.
 *
 * It is also why the switcher is RENDERED from `/settings` and nowhere else: `router.push` is
 * fire-and-forget, so step 1 cannot be awaited, and a mounted data view renders the instant its cache
 * has anything. Putting the control on the neutral route makes step 1 a no-op and removes the race
 * instead of narrowing it.
 */
export const NEUTRAL_ROUTE = '/settings';

/**
 * The five steps as injectable dependencies, so `tests/unit/switch-organization.test.ts` can assert
 * the ORDER — which is the thing that is easy to break and impossible to see in a rendered result.
 */
export interface SwitchOrganizationSteps {
  /** Step 0. Membership as the server most recently reported it. */
  readonly isMember: (organizationId: string) => boolean;
  /** Step 1. */
  readonly navigateToNeutralRoute: () => void;
  /** Step 2. */
  readonly cancelQueries: () => Promise<void>;
  /** Step 3. */
  readonly switchOrganization: (organizationId: string) => Promise<unknown>;
  /** Step 4 — THE ENFORCEMENT. */
  readonly resetQueryClient: () => void;
  /** Step 5. */
  readonly refreshRouterCache: () => void;
}

/**
 * ```
 * 0. GUARD    — the id must be one this server just handed us in `organizations[]`, and ACTIVE.
 * 1. NAVIGATE — to a neutral shell first; a mounted data view renders the instant its cache has
 *               anything. From `/settings` this is a no-op, which is why the control lives there.
 * 2. CANCEL   — `await queryClient.cancelQueries()`. A no-op unless every queryFn forwards its signal.
 * 3. AWAIT    — the switch mutation. A per-call `retry` property is an ESLint error and the global
 *               mutation default is already zero: a replayed switch races itself.
 * 4. REPLACE  — the QueryClient. NEVER `clear()` (src/components/providers.tsx:14-17).
 * 5. REFRESH  — `router.refresh()`, to drop the Client Router Cache, which is keyed by path and
 *               therefore holds RSC payloads captured under the previous organization.
 * ```
 * Step 4 destroys the `['session']` entry too, and that is correct: the new client refetches it and
 * the new document names the new `current_organization_id`. The switcher therefore has to survive its
 * own reset — it re-enters `status: 'loading'` for one round trip, which is why the control is
 * disabled on `loading` as well as on `isPending`.
 *
 * There is NO full document navigation here, unlike logout: identity did not change, and steps 4+5
 * cover both surviving caches. A reload would also be correct and would throw away "you stay where
 * you were" for no additional safety.
 *
 * It THROWS on a failed step 3 and therefore skips 4 and 5 deliberately: resetting the client on a
 * failed switch would discard the error the user needs to read, and refreshing the Router Cache would
 * suggest something changed when nothing did.
 */
export async function performOrganizationSwitch(
  steps: SwitchOrganizationSteps,
  nextOrganizationId: string,
): Promise<void> {
  if (!steps.isMember(nextOrganizationId)) return;

  steps.navigateToNeutralRoute();
  await steps.cancelQueries();
  await steps.switchOrganization(nextOrganizationId);
  steps.resetQueryClient();
  steps.refreshRouterCache();
}
