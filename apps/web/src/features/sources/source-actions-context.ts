import { createContext, useContext } from 'react';

/**
 * WHAT A ROW ACTION NEEDS THAT A ROW DOES NOT CARRY, handed down by the screen rather than threaded
 * through the column definitions.
 *
 * ── WHY A CONTEXT AND NOT A PROP ────────────────────────────────────────────────────────────────
 * `ServerDataTable`'s `columns` must be REFERENTIALLY STABLE — module scope, or `useMemo` — because a
 * fresh array each render rebuilds the core row model and re-renders every row on unrelated state
 * changes. A column whose cell closes over `orgId` and the list's query key cannot be declared at
 * module scope, so the choice is a `useMemo` over the whole column set (which then has to be right
 * in every feature that copies it) or one context read inside the one cell that needs it. This is the
 * second, and it keeps `source-columns.tsx` a module-scope constant like `bot-columns.tsx`.
 *
 * ── IT IS A `.ts` AND CARRIES NO JSX, DELIBERATELY ──────────────────────────────────────────────
 * Same constraint `features/auth/session-context.ts` records: the `unit` Vitest project runs in
 * `node` with no react plugin and `jsx: "preserve"`, so any module a `tests/unit/**` spec reaches
 * transitively must be JSX-free or Vite fails the whole FILE with "content contains invalid JS
 * syntax" — naming this module rather than the spec. The provider is JSX and lives in
 * `sources-screen.tsx`, which is where the boundary actually is.
 *
 * ── THE ORGANIZATION IS HERE BECAUSE IT IS TWO DIFFERENT THINGS ─────────────────────────────────
 * `orgId` goes in the mutation's PATH, where it is a routing hint Laravel re-derives from the session
 * anyway; `listKey` is the org-namespaced query key PREFIX to invalidate, where the same string is a
 * cache namespace. Both come from the screen so the row cannot build either by hand, and the prefix
 * in particular must be `['org', orgId, 'sources']` and NOT the full key with `requestParams` on the
 * end — a status change moves a row between pages the current view is not looking at.
 */
export interface SourceActions {
  readonly orgId: string;
  /** `orgKeyFor('sources')` — the PREFIX, so invalidating it reaches every page and sort of this list. */
  readonly listKey: readonly unknown[];
  /**
   * Whether this viewer holds `sources.manage` in this organization. AN AFFORDANCE, NEVER
   * AUTHORIZATION — Laravel answers 403 whatever this says, and a role that changed under a cached
   * session shows up as that 403 rather than as a silently missing control.
   */
  readonly canManage: boolean;
}

export const SourceActionsContext = createContext<SourceActions | null>(null);

/**
 * THROWS rather than degrading. A row action rendered outside the provider would otherwise mutate
 * against `undefined` in a path segment — a request to `/organizations/undefined/sources/…`, which
 * 404s at binding time and renders as `authorization`, i.e. as a permissions problem that is really a
 * wiring bug. The throw names the real cause on the first render.
 */
export function useSourceActions(): SourceActions {
  const actions = useContext(SourceActionsContext);
  if (actions === null) {
    throw new Error('useSourceActions() used outside <SourceActionsContext>.');
  }
  return actions;
}
