import type { RowData } from '@tanstack/react-table';
import {
  createColumnHelper,
  rowPaginationFeature,
  rowSortingFeature,
  tableFeatures,
} from '@tanstack/react-table';

/**
 * The feature set every server-driven table in this app is built from — declared ONCE, at module
 * scope, and shared.
 *
 * ── WHAT IS DELIBERATELY ABSENT ──────────────────────────────────────────────────────────────────
 * No `createSortedRowModel`, no `createPaginatedRowModel`, no `createFilteredRowModel`. The server
 * owns the tenant filter, so the browser holds one org-scoped page of a set it cannot see; sorting 25
 * of 4,000 rows client-side produces an ordering that is wrong and looks right
 * (`tanstack-query-table` NN6). Registering a row-model factory here would make that one prop away.
 *
 * Also absent: `useLegacyTable` and `stockFeatures`. Both exist for a v8 migration we do not have, and
 * the v9 migration guide states `stockFeatures` produces a larger bundle than v8 did.
 *
 * A SHARED, MODULE-SCOPE OBJECT IS ALSO A CORRECTNESS REQUIREMENT, not just tidiness: `features` is
 * read on every render and an unstable identity rebuilds the core row model each time.
 */
export const serverTableFeatures = tableFeatures({
  rowSortingFeature,
  rowPaginationFeature,
  /**
   * The type-only `columnMeta` slot (the value is phantom and stripped at runtime). Declaring it here
   * rather than by global declaration merging keeps `columnDef.meta` typed as exactly this — and
   * `ServerColumnMeta` is what lets the two layouts below 768px and above it be fed from ONE set of
   * column definitions.
   */
  columnMeta: {} as ServerColumnMeta,
});

export type ServerTableFeatures = typeof serverTableFeatures;

/**
 * Per-column presentation, carried on the column definition so the table layout and the card layout
 * cannot disagree about what a column is.
 */
export interface ServerColumnMeta {
  /**
   * `'end'` for numeric columns. P6's cell types are a closed set and a numeric cell is right-aligned
   * with tabular figures; `globals.css` already applies `font-variant-numeric` to every `td`.
   */
  readonly align?: 'end';
  /**
   * Where this column goes in the BELOW-768px card layout, which is a stack of row-cards rather than
   * a horizontal scroller — a scroller hides the actions column, which is where the destructive
   * action lives (`components/data-table.tsx`).
   *
   *   `'title'`  the card's heading. Exactly one column should carry it; the first cell is the
   *              fallback, because a card with no title is a card nobody can identify.
   *   `'field'`  a labelled pair in the card body. The default.
   *   `'action'` rendered in the card's action row, never as a labelled pair.
   *   `'hidden'` present in the table, dropped from the card. For the third and fourth secondary
   *              column: a card with nine labelled pairs is a table with extra steps.
   */
  readonly card?: 'title' | 'field' | 'action' | 'hidden';
  /**
   * The `<dt>` text in the card layout when the column's header is not a plain string — an icon-only
   * or visually-hidden header has no text to reuse, and an unlabelled `<dd>` is an unreadable card.
   */
  readonly cardLabel?: string;
}

/**
 * Column definitions for a server-driven table.
 *
 * Build them with `helper.columns([...])` rather than a bare array literal: the helper preserves each
 * column's own value type through a variadic tuple, and the resulting array is the shape assignable
 * to `useTable`'s `columns`. A bare array of `helper.accessor(...)` results is typed per element and
 * will not assign, which is TanStack's design rather than ours.
 */
export function createServerColumnHelper<TData extends RowData>() {
  return createColumnHelper<ServerTableFeatures, TData>();
}

/**
 * The stable empty array for `data` while a query is pending.
 *
 * `data={query.data?.rows ?? []}` is a fresh array identity on every render, which invalidates the
 * core row model and re-renders every row on unrelated state changes (`tanstack-query-table`
 * gotcha 9). `readonly never[]` is assignable to `ReadonlyArray<TData>` for any `TData`.
 */
export const EMPTY_ROWS: readonly never[] = [];
