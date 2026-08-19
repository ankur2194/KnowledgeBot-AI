'use client';

import type { OnChangeFn, PaginationState, SortingState } from '@tanstack/react-table';
import { functionalUpdate } from '@tanstack/react-table';
import { usePathname, useRouter, useSearchParams } from 'next/navigation';
import { useCallback, useMemo } from 'react';

import {
  assertTableParamsConfig,
  hasActiveFilter,
  readTableParams,
  toRequestParams,
  writeTableParams,
  type SortParam,
  type TableParams,
  type TableParamsConfig,
} from './params';

/**
 * The URL as the single store for a server-driven table's state.
 *
 * ── THE ONE THING THIS EXISTS FOR ────────────────────────────────────────────────────────────────
 * `manualPagination: true` automatically disables `autoResetPageIndex`, so the table will NEVER reset
 * the page when a sort or a filter changes. Doing it yourself in a second `setState` is the bug that
 * requests page 8 of a 2-page result and renders "no matches" for a collection with hundreds of rows
 * (`tanstack-query-table` gotcha 3). Here there is no second update to get wrong: every mutation below
 * builds ONE complete `TableParams` value and performs ONE navigation, and the ones that change what
 * the result set IS carry `pageIndex: 0` in that same object literal.
 *
 * ── WHY `replace` FOR EVERYTHING EXCEPT THE PAGE ─────────────────────────────────────────────────
 * A debounced search box writes a URL per pause; with `push` that is one history entry per pause, and
 * Back walks the user backwards through their own typing. Paging is the one movement a reader thinks
 * of as going somewhere, so `setPageIndex` pushes and Back returns to the page they came from.
 *
 * ── WHAT THIS HOOK DOES NOT DO ───────────────────────────────────────────────────────────────────
 * It does not fetch, and it holds no organization. `requestParams` is the tail of a query key whose
 * head is `['org', orgId, …]` from `useOrgKey()`; the organization is in the session cookie and never
 * in the URL (`nextjs-app-router`, `kb-tenancy-isolation` NN6).
 *
 * ── TWO REQUIREMENTS ON THE CALLER ───────────────────────────────────────────────────────────────
 *   1. DECLARE `config` AT MODULE SCOPE. An object literal in the render body is a new identity every
 *      render, which rebuilds the params, the Map and every callback each time.
 *   2. `useSearchParams()` opts the route out of static rendering. Every route under `(admin)` is
 *      already `force-dynamic` (nothing org-scoped may be produced by the Next server at all), so
 *      this costs nothing there; anywhere else the caller owes a `<Suspense>` boundary.
 */
export interface TableParamsResult {
  /** The decoded view. `filters` is a Map in the config's declared order. */
  readonly params: TableParams;
  /** `state.sorting` for `useTable`. Always exactly one entry — see `TableParamsConfig.defaultSort`. */
  readonly sorting: SortingState;
  /** `state.pagination` for `useTable`. */
  readonly pagination: PaginationState;
  /** `onSortingChange` for `useTable`. Resets the page in the same navigation. */
  readonly onSortingChange: OnChangeFn<SortingState>;
  /** `onPaginationChange` for `useTable`. A page-size change resets the page; a page change does not. */
  readonly onPaginationChange: OnChangeFn<PaginationState>;
  /** `null` or `''` removes the filter. Resets the page in the same navigation. */
  readonly setFilter: (name: string, value: string | null) => void;
  /** Drops every filter and returns to the first page. The action on the FILTERED empty state. */
  readonly clearFilters: () => void;
  /** The action on an out-of-range page, which only a hand-typed URL can reach. */
  readonly goToFirstPage: () => void;
  /** Whether any filter is applied — the basis of the first-run/filtered empty split. */
  readonly isFiltered: boolean;
  /**
   * Exactly what goes on the wire, and exactly what goes in the query key after the org namespace.
   * One value for both, so a response can never be cached under a question it does not answer.
   */
  readonly requestParams: Readonly<Record<string, string>>;
  /**
   * Echoed from the config, because the component that renders the pager needs the choices and taking
   * them from anywhere else is how a select comes to offer a size the parser discards — the URL then
   * says `per_page=50`, the request says 25, and the pager's arithmetic is right about neither.
   */
  readonly pageSizes: readonly number[];
}

type HistoryMode = 'push' | 'replace';

export function useTableParams(config: TableParamsConfig): TableParamsResult {
  assertTableParamsConfig(config);

  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();
  // The STRING is the dependency, not the object: Next hands back a fresh `ReadonlyURLSearchParams`
  // on renders that did not navigate, and keying on the identity would rebuild everything downstream.
  const search = searchParams.toString();

  const params = useMemo(() => readTableParams(new URLSearchParams(search), config), [search, config]);

  const write = useCallback(
    (next: TableParams, history: HistoryMode) => {
      const query = writeTableParams(next, config, new URLSearchParams(search));
      const href = query === '' ? pathname : `${pathname}?${query}`;
      // `scroll: false` on both: a pager at the bottom of a long table that jumps to the top on every
      // Next is a table you cannot page through without scrolling back down each time.
      if (history === 'push') router.push(href, { scroll: false });
      else router.replace(href, { scroll: false });
    },
    [config, pathname, router, search],
  );

  const setSort = useCallback(
    (sort: SortParam) => {
      // `pageIndex: 0` lives in the SAME object as the new sort. There is no window in which the
      // request carries the new ordering and the old page.
      write({ ...params, sort, pageIndex: 0 }, 'replace');
    },
    [params, write],
  );

  const onSortingChange = useCallback<OnChangeFn<SortingState>>(
    (updater) => {
      const next = functionalUpdate(updater, [{ id: params.sort.id, desc: params.sort.desc }]);
      const first = next[0];
      // An empty `SortingState` means the table tried to remove the sort. We refuse it rather than
      // forward an unordered request: offset pagination over an unordered set may repeat or skip rows
      // between pages. `enableSortingRemoval: false` on the table means this arm is unreachable from
      // the UI; it is here because `onSortingChange` is a public callback.
      setSort(first === undefined ? config.defaultSort : { id: first.id, desc: first.desc });
    },
    [config.defaultSort, params.sort.desc, params.sort.id, setSort],
  );

  const onPaginationChange = useCallback<OnChangeFn<PaginationState>>(
    (updater) => {
      const next = functionalUpdate(updater, {
        pageIndex: params.pageIndex,
        pageSize: params.pageSize,
      });
      if (next.pageSize !== params.pageSize) {
        // Changing the page size changes what "page 4" means, so it resets — in the same object, and
        // with `replace`, because it re-slices the view rather than moving through it.
        write({ ...params, pageSize: next.pageSize, pageIndex: 0 }, 'replace');
        return;
      }
      write({ ...params, pageIndex: Math.max(0, next.pageIndex) }, 'push');
    },
    [params, write],
  );

  const setFilter = useCallback(
    (name: string, value: string | null) => {
      const filters = new Map(params.filters);
      const trimmed = value?.trim() ?? '';
      if (trimmed === '') filters.delete(name);
      else filters.set(name, trimmed);
      write({ ...params, filters, pageIndex: 0 }, 'replace');
    },
    [params, write],
  );

  const clearFilters = useCallback(() => {
    write({ ...params, filters: new Map(), pageIndex: 0 }, 'replace');
  }, [params, write]);

  const goToFirstPage = useCallback(() => {
    write({ ...params, pageIndex: 0 }, 'replace');
  }, [params, write]);

  const sorting = useMemo<SortingState>(
    () => [{ id: params.sort.id, desc: params.sort.desc }],
    [params.sort.desc, params.sort.id],
  );
  const pagination = useMemo<PaginationState>(
    () => ({ pageIndex: params.pageIndex, pageSize: params.pageSize }),
    [params.pageIndex, params.pageSize],
  );
  const requestParams = useMemo(() => toRequestParams(params), [params]);

  return {
    params,
    sorting,
    pagination,
    onSortingChange,
    onPaginationChange,
    setFilter,
    clearFilters,
    goToFirstPage,
    isFiltered: hasActiveFilter(params),
    requestParams,
    pageSizes: config.pageSizes,
  };
}
