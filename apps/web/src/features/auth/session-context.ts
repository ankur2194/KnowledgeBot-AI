import { createContext, useCallback, useContext } from 'react';

import type { SessionState } from './session';

import { orgKey } from '@/lib/query/client';

/**
 * The session CONTEXT and its three readers — the client-only half of the session layer.
 *
 * ── WHY THIS IS A SEPARATE MODULE (decision D38, with its premise corrected) ─────────────────────
 * `session.ts` used to hold all of this beside the fetchers, so that module imported `createContext`
 * and every consumer of a path constant was coupled to the client runtime. Separating them means a
 * server component can import the pure half and a reader can tell which half is safe where.
 *
 * WHAT THIS IS *NOT*: a fix for a build failure. D38 recorded that a server module importing
 * `session.ts` "broke the RSC compile" because the chain reached `createContext`. That was tested
 * directly afterwards and it does not reproduce — a server page importing a module that calls
 * `createContext` at module scope compiles clean, and so does one importing a hook and calling it,
 * because every route here is dynamic so nothing prerenders and no hook runs at build time. Whatever
 * went red in the tree that produced `src/lib/auth/single-token.ts` was something else, and the
 * mechanism is recorded as unknown rather than repeated. `pnpm web:build` will NOT catch a regression
 * of this boundary, which is why the constraint is stated in prose in both files.
 *
 * ── IT IS A `.ts` AND NOT A `.tsx`, AND THAT IS LOAD-BEARING ─────────────────────────────────────
 * The obvious home for a context is beside its provider in `session-provider.tsx`. It cannot go there.
 * The `unit` Vitest project runs in `node` and installs NO react plugin, while `tsconfig.json` sets
 * `jsx: "preserve"` because Next owns that transform — so esbuild leaves JSX verbatim and Vite fails
 * the whole FILE with "content contains invalid JS syntax". Any module a `tests/unit/**` spec reaches
 * transitively must therefore be JSX-FREE, and `use-switch-organization.ts` reaches `useSession`.
 *
 * `createContext` is not JSX, so this file satisfies both constraints at once: no JSX for the node
 * project, and no `react` import left in `session.ts` for the server graph. Do not add a component
 * here — the moment this file gains JSX, the unit project starts failing on files that merely import
 * it, and the error names this module rather than the spec.
 *
 * ── NO `'use client'` DIRECTIVE, DELIBERATELY ────────────────────────────────────────────────────
 * A module reached only from client components is already in the client graph, so the directive would
 * add nothing. `session-provider.tsx` carries it, because that is where the boundary actually is.
 *
 * Adding it here would also be misleading in the one way that matters: `'use client'` marks a module as
 * belonging to the client bundle, not as safe to import from a server component — and per the
 * correction above, nothing in the toolchain refuses that import either way.
 */

/** Written only by `<SessionProvider>` in session-provider.tsx. */
export const SessionContext = createContext<SessionState | null>(null);

export function useSession(): SessionState {
  const state = useContext(SessionContext);
  if (state === null) {
    throw new Error('useSession used outside <SessionProvider>');
  }
  return state;
}

/**
 * The current organization, or `null`.
 *
 * `null` MATTERS AND IS NOT AN EDGE CASE. `orgKey(undefined, 'sources')` is
 * `['org', undefined, 'sources']` — ONE shared cache namespace for every org-less state on the
 * platform, which is precisely the leak the prefix exists to prevent. So every org-scoped query must
 * be `enabled: orgId !== null`, and this returns `null` for all three of loading, anonymous and
 * "authenticated with no current organization" rather than inventing a placeholder.
 *
 * The third of those is reachable BY DESIGN: login succeeds with `current_organization_id: null` for a
 * user whose memberships are all `invited` or `suspended`, because refusing the login would leave the
 * resend-verification and accept-invitation endpoints unreachable.
 */
export function useCurrentOrgId(): string | null {
  const state = useSession();
  return state.status === 'authenticated' ? state.orgId : null;
}

/**
 * The PRODUCER the `orgKey` docblock in src/lib/query/client.ts has been waiting for.
 *
 * It THROWS rather than returning a degraded key when there is no organization. A key built from
 * `undefined` collapses every org-less state into one namespace and the symptom is a correctly
 * rendered list belonging to nobody; a throw is a component that forgot `enabled: orgId !== null`,
 * which the developer sees on the first render.
 */
export function useOrgKey(): (...rest: readonly unknown[]) => readonly unknown[] {
  const orgId = useCurrentOrgId();
  return useCallback(
    (...rest: readonly unknown[]) => {
      if (orgId === null) {
        throw new Error(
          'useOrgKey() called with no current organization. Gate the query on `enabled: orgId !== null`.',
        );
      }
      return orgKey(orgId, ...rest);
    },
    [orgId],
  );
}
