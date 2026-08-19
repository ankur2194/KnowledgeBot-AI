/**
 * THE URL IS THE TABLE'S STATE, AND THE URL IS ALSO THE REQUEST.
 *
 * Every server-visible sort, filter and page value lives in the query string under the same names
 * Laravel reads them by (`page`, `per_page`, `sort`, plus each filter's own name). Three properties
 * follow from that one decision, and each of them is a bug this repo would otherwise have:
 *
 *   1. A FILTERED VIEW IS SHAREABLE AND SURVIVES RELOAD. `kb-ui-patterns` P9: "All of it lives in the
 *      URL. A filtered view a user cannot share or reload is unfinished."
 *   2. THE PAGE RESET CANNOT BE SPLIT IN TWO. Under `manualPagination` the table never resets
 *      `pageIndex` for you (`autoResetPageIndex` is auto-disabled), and doing it in a second
 *      `setState` is what shows page 7 of a 2-page result. Here the whole view is ONE value written
 *      in ONE navigation, so "reset the page in the same update as the sort" is not a rule anyone has
 *      to remember — there is no expression for the other thing.
 *   3. THE QUERY KEY AND THE REQUEST CANNOT DRIFT. `toRequestParams()` produces both: the object that
 *      goes into `['org', orgId, 'bots', …]` after the org namespace, and the object that goes on the
 *      wire. A key missing a value the request carries is a page-2 response overwriting the page-1
 *      cache entry (`tanstack-query-table`).
 *
 * THE ORGANIZATION IS NOT IN HERE AND MUST NEVER BE. It lives in the session cookie; Laravel derives
 * it and would ignore a client-supplied one. It is a query-key namespace, not a request parameter
 * (`kb-tenancy-isolation` NN6).
 *
 * ── WHY EVERY VALUE READ FROM THE URL IS CLAMPED TO A DECLARED SET ───────────────────────────────
 * A query string is user input, and it reaches two places that must not take unbounded strings: the
 * request Laravel validates (an unknown `sort` is a 422 rendered as an error screen for a URL somebody
 * merely typed) and the TanStack Query cache key (an unbounded string is unbounded cache entries in one
 * browser tab). So a `sort` outside `sortableColumns` degrades to the default, a `per_page` outside
 * `pageSizes` degrades to the default, and a filter value is truncated. A stale bookmark from a
 * previous release renders the default view instead of an error.
 *
 * Pure: no React, no `next/navigation`, no `window`. The hook is `use-table-params.ts`.
 */

/**
 * One column, one direction. Multi-sort is deliberately not representable — see `parseSort`.
 *
 * `desc` rather than a `'asc' | 'desc'` string because that is what TanStack's `ColumnSort` holds;
 * the wire spelling is `dir=asc|desc` and the conversion happens once, here.
 */
export interface SortParam {
  readonly id: string;
  readonly desc: boolean;
}

export interface TableParamsConfig {
  /**
   * The column ids the SERVER can order by. Not the columns the table renders: a column the server
   * cannot sort must not be clickable, and a `sort` value outside this list is discarded rather than
   * forwarded.
   */
  readonly sortableColumns: readonly string[];
  /**
   * Required, and there is no "unsorted" state. Offset pagination over an unordered result set is
   * free to repeat a row on page 2 that it already showed on page 1 — PostgreSQL gives no ordering
   * guarantee without ORDER BY — so "sorted by something deterministic" is a correctness requirement
   * here rather than a preference. This value is also the one omitted from a canonical URL.
   */
  readonly defaultSort: SortParam;
  /** Query-string names the server filters by, e.g. `['q', 'status']`. Order is stable for display. */
  readonly filterNames: readonly string[];
  /**
   * Allowed `per_page` values. The FIRST is the default and the one omitted from a canonical URL.
   *
   * Every value must be <= `MAX_PER_PAGE`. The server CLAMPS silently for callers that reach
   * `ListQuery::fromValidated()` without a FormRequest, and a clamped response is one whose applied
   * page size differs from the one this table is doing its range arithmetic with — "51–100 of 137"
   * over a page that actually held 100 rows starting at row 1.
   */
  readonly pageSizes: readonly number[];
}

export interface TableParams {
  readonly sort: SortParam;
  /** ZERO-BASED, because that is what TanStack Table's `PaginationState` holds. The URL is 1-based. */
  readonly pageIndex: number;
  readonly pageSize: number;
  /**
   * A `Map`, not a `Record`, for two reasons: iteration order is the declared filter order, and
   * reading a `Record` by a non-literal key is the `security/detect-object-injection` sink this
   * module would otherwise trip on every access.
   */
  readonly filters: ReadonlyMap<string, string>;
}

/**
 * THE FOUR NAMES LARAVEL READS, verbatim (`App\Support\Http\ListQuery::rules()`).
 *
 * `sort` is the bare column and `dir` is `asc`/`desc` — two parameters, not a `-column` prefix. The
 * direction is a closed enum one layer below the validation rule because the value reaches an
 * `ORDER BY`; a client that invented `sort=-name` would be validated against `Rule::in($sortable)`
 * and 422 on every sorted request.
 */
export const PAGE_PARAM = 'page';
export const PER_PAGE_PARAM = 'per_page';
export const SORT_PARAM = 'sort';
export const DIR_PARAM = 'dir';

/**
 * Mirrors `ListQuery::MAX_PER_PAGE`. A mirror, not an authority: the server clamps regardless, and
 * this only makes a config that would be silently clamped fail loudly at the call site instead.
 */
export const MAX_PER_PAGE = 100;

/**
 * A filter value longer than this is truncated rather than dropped or forwarded. Dropping it would
 * silently render an unfiltered list for a URL that asks for a filter; forwarding it makes the cache
 * key as long as the address bar allows.
 *
 * The number mirrors `ListQuery::MAX_FILTER_LENGTH`, and the server's rule is the authority: a
 * longer value is a 422 there, not a shorter search here. This bounds what we are willing to put in
 * a cache key, which is a different question from what the endpoint accepts.
 */
export const MAX_FILTER_LENGTH = 200;

/** The names this module owns in a query string. A filter may not reuse one. */
export function reservedParamNames(): readonly string[] {
  return [PAGE_PARAM, PER_PAGE_PARAM, SORT_PARAM, DIR_PARAM];
}

/**
 * Throws on a config that would produce a URL this module cannot round-trip. Called by the hook on
 * every render — it is three array scans over a handful of literals, and the alternative is a filter
 * named `page` that silently eats the pager.
 */
export function assertTableParamsConfig(config: TableParamsConfig): void {
  if (config.pageSizes.length === 0) {
    throw new Error('TableParamsConfig.pageSizes must not be empty: pageSizes[0] is the default.');
  }
  if (!config.sortableColumns.includes(config.defaultSort.id)) {
    throw new Error(
      `TableParamsConfig.defaultSort ("${config.defaultSort.id}") must be one of sortableColumns.`,
    );
  }
  const oversized = config.pageSizes.find((size) => size > MAX_PER_PAGE);
  if (oversized !== undefined) {
    throw new Error(
      `TableParamsConfig.pageSizes has ${oversized}, above the platform maximum of ${MAX_PER_PAGE}: ` +
        'the server would clamp it silently and the pager would state a range nobody was served.',
    );
  }
  const reserved = reservedParamNames();
  for (const name of config.filterNames) {
    if (reserved.includes(name)) {
      throw new Error(`A filter may not be named "${name}": that parameter belongs to the pager.`);
    }
  }
}

/**
 * `sort=<column>` plus `dir=asc|desc`. A column outside the endpoint's closed set yields `null` and
 * the caller falls back to the default.
 *
 * MULTI-SORT IS NOT ACCEPTED even though `SortingState` is an array: two sort keys mean the server
 * must agree on the tie-break, and `Rule::in($sortable)` would 422 a comma-separated value anyway.
 *
 * AN UNRECOGNISED `dir` READS AS ASCENDING rather than as no sort at all, matching
 * `ListQuery::fromValidated()`, which falls back to the endpoint's default direction when `dir` is
 * absent or unparseable. Discarding the whole sort because the direction was mistyped would throw
 * away the half of the URL that was correct.
 */
export function parseSort(
  rawSort: string | null,
  rawDir: string | null,
  config: TableParamsConfig,
): SortParam | null {
  if (rawSort === null || rawSort === '') return null;
  if (!config.sortableColumns.includes(rawSort)) return null;
  return { id: rawSort, desc: rawDir === 'desc' };
}

/** The wire spelling of the direction: `SortDirection` is a two-case backed enum on the server. */
export function formatDirection(sort: SortParam): 'asc' | 'desc' {
  return sort.desc ? 'desc' : 'asc';
}

export function sameSort(a: SortParam, b: SortParam): boolean {
  return a.id === b.id && a.desc === b.desc;
}

function parsePageIndex(raw: string | null): number {
  if (raw === null) return 0;
  const page = Number.parseInt(raw, 10);
  // `Number.isSafeInteger` rejects NaN, Infinity and 1e21 alike. A page below 1 is not an error the
  // user needs told about — it is a malformed URL, and the first page is what they were asking for.
  if (!Number.isSafeInteger(page) || page < 1) return 0;
  return page - 1;
}

function parsePageSize(raw: string | null, config: TableParamsConfig): number {
  const fallback = config.pageSizes[0] ?? 25;
  if (raw === null) return fallback;
  const size = Number.parseInt(raw, 10);
  if (!Number.isSafeInteger(size)) return fallback;
  return config.pageSizes.includes(size) ? size : fallback;
}

/** Reads the whole view out of a query string. Never throws; every malformed value degrades. */
export function readTableParams(
  search: URLSearchParams,
  config: TableParamsConfig,
): TableParams {
  const filters = new Map<string, string>();
  for (const name of config.filterNames) {
    const value = search.get(name)?.trim() ?? '';
    if (value !== '') filters.set(name, value.slice(0, MAX_FILTER_LENGTH));
  }

  return {
    sort: parseSort(search.get(SORT_PARAM), search.get(DIR_PARAM), config) ?? config.defaultSort,
    pageIndex: parsePageIndex(search.get(PAGE_PARAM)),
    pageSize: parsePageSize(search.get(PER_PAGE_PARAM), config),
    filters,
  };
}

/**
 * The canonical query string for a view, preserving any parameter this module does not own (a tab, a
 * highlighted row id, an OAuth `state`).
 *
 * DEFAULTS ARE OMITTED, and that is not cosmetic. Two spellings of the same view — `/bots` and
 * `/bots?page=1&per_page=25&sort=name` — are two entries in the browser's history and two identical
 * fetches whose only difference is a URL nobody typed. One view, one URL.
 */
export function writeTableParams(
  params: TableParams,
  config: TableParamsConfig,
  base?: URLSearchParams,
): string {
  const next = new URLSearchParams(base);

  for (const name of reservedParamNames()) next.delete(name);
  for (const name of config.filterNames) next.delete(name);

  // BOTH or NEITHER. Writing `sort` without `dir` would leave the direction to the endpoint's
  // default, which is not necessarily the one the user is looking at — and two URLs that mean the
  // same view are two history entries and two cache entries.
  if (!sameSort(params.sort, config.defaultSort)) {
    next.set(SORT_PARAM, params.sort.id);
    next.set(DIR_PARAM, formatDirection(params.sort));
  }
  if (params.pageSize !== config.pageSizes[0]) next.set(PER_PAGE_PARAM, String(params.pageSize));
  if (params.pageIndex > 0) next.set(PAGE_PARAM, String(params.pageIndex + 1));
  // Declared order rather than Map insertion order, so two paths to the same view produce the same
  // string — the hook rebuilds the Map from the URL on every read, but a caller may not.
  for (const name of config.filterNames) {
    const value = params.filters.get(name);
    if (value !== undefined && value !== '') next.set(name, value);
  }

  return next.toString();
}

/**
 * The request Laravel receives, and — after `['org', orgId, <resource>]` — the tail of the query key.
 * ONE function for both, because a value in the request that is missing from the key is a response
 * cached under a question it does not answer.
 *
 * Everything is explicit here even where `writeTableParams` omits it: the URL's defaults are ours and
 * the server's defaults are the server's, and they are not required to agree.
 */
export function toRequestParams(params: TableParams): Readonly<Record<string, string>> {
  return Object.fromEntries([
    [PAGE_PARAM, String(params.pageIndex + 1)],
    [PER_PAGE_PARAM, String(params.pageSize)],
    [SORT_PARAM, params.sort.id],
    [DIR_PARAM, formatDirection(params.sort)],
    ...params.filters,
  ]);
}

/**
 * Whether ANY filter is applied — which is the whole basis of the first-run/filtered empty split.
 *
 * Sort and page are deliberately not filters: neither can turn a populated collection into an empty
 * page (an out-of-range page can, and the table handles that separately, because "clear your filters"
 * is the wrong advice for somebody who typed `?page=99`).
 */
export function hasActiveFilter(params: TableParams): boolean {
  return params.filters.size > 0;
}
