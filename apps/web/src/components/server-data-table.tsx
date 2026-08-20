'use client';

import type { Cell, ColumnDef, Header, RowData } from '@tanstack/react-table';
import { KbError } from '@kb/contracts';
import { useTable } from '@tanstack/react-table';
import {
  ChevronDownIcon,
  ChevronLeftIcon,
  ChevronRightIcon,
  ChevronUpIcon,
  ChevronsUpDownIcon,
} from 'lucide-react';
import { useId, type ReactNode } from 'react';

import {
  DataTableCard,
  DataTableCardField,
  DataTableCards,
  DataTableFooterBar,
  DataTableHeaderBar,
  DataTableScroller,
  DataTableSurface,
} from '@/components/data-table';
import {
  ErrorState,
  FilteredEmptyState,
  ForbiddenState,
  PageOutOfRangeState,
  RefetchIndicator,
  TableSkeleton,
} from '@/components/states';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { EMPTY_ROWS, serverTableFeatures, type ServerTableFeatures } from '@/lib/table/features';
import type { TableParamsResult } from '@/lib/table/use-table-params';
import { cn } from '@/lib/utils';

/**
 * THE server-driven table. Every paginated collection in the admin console renders through this one
 * component; nothing here knows what a bot, a source or a conversation is.
 *
 * ── SERVER-DRIVEN MEANS FOUR THINGS TOGETHER, AND THREE OF THEM ARE SILENT WHEN MISSING ──────────
 *   `manualSorting: true`    the browser holds one page of a set it cannot see; sorting 25 of 4,000
 *                            rows produces an ordering that is wrong and looks right.
 *   `manualPagination: true` same reason, and it also auto-disables `autoResetPageIndex` — which is
 *                            why the page reset lives in `useTableParams`, in the same navigation as
 *                            the change that caused it.
 *   `rowCount` from the envelope's `meta.total`. Without it the table treats `data.length` as
 *                            everything and the pager reads "Page 1 of 1" on a 4,000-row set; with
 *                            `pageCount: -1` instead, Next never disables. While it is UNKNOWN the
 *                            pager renders as a skeleton rather than defaulting to 0 — a pager that
 *                            states a wrong total is worse than one that has not loaded.
 *   no client row models     `lib/table/features.ts` registers none, so none can be switched on here.
 *
 * ── THE FOUR STATES SHIP WITH THE SUCCESS STATE ──────────────────────────────────────────────────
 * They are not optional props with `null` defaults. `status` is exhaustive, the empty case splits on
 * `view.isFiltered` — which is derived from the URL rather than passed as a boolean somebody can get
 * backwards — and `error` routes to FORBIDDEN when its class is `authorization` and a `requiredRole`
 * was named. Offering "Create your first bot" to somebody whose search matched nothing is the bug the
 * split exists to prevent (`kb-ui-patterns` → references/states.md).
 *
 * ── ONE DATA SOURCE, TWO LAYOUTS ─────────────────────────────────────────────────────────────────
 * Above 768px the real `<table>` with `<th scope>`; below it a stack of row-cards, because a
 * horizontal scroller hides the actions column and that is where the destructive action lives. Both
 * iterate the SAME `table.getRowModel().rows`, and which cell goes where in the card is declared on
 * the column definition (`ServerColumnMeta`) — so the two layouts cannot drift.
 *
 * ── WHAT THIS COMPONENT DOES NOT DO ──────────────────────────────────────────────────────────────
 * It does not fetch, does not know an organization, and holds no state of its own. Rows arrive from a
 * TanStack Query call whose key begins `['org', orgId, …]` and whose tail is `view.requestParams`.
 */
export type ServerDataTableStatus = 'pending' | 'error' | 'success';

export interface ServerDataTableProps<TData extends RowData> {
  /**
   * The table's accessible name, rendered as a visually hidden `<caption>`. A page with two tables
   * and no captions gives a screen-reader user two things called "table".
   */
  readonly caption: string;
  /**
   * From `columnHelper.columns([...])`. The helper's array form preserves each column's value type
   * and is the shape assignable here; a bare array literal of `helper.accessor(...)` results is typed
   * per element and will not assign. That is TanStack's design, not ours.
   *
   * MUST BE REFERENTIALLY STABLE — module scope, or `useMemo`. A fresh array each render rebuilds the
   * column model every time.
   */
  readonly columns: ReadonlyArray<ColumnDef<ServerTableFeatures, TData>>;
  /** `undefined` while the first page is loading. Never `?? []` at the call site: see `EMPTY_ROWS`. */
  readonly rows: readonly TData[] | undefined;
  /** `meta.total` from the envelope. `undefined` renders the pager as a skeleton. */
  readonly rowCount: number | undefined;
  /** A STABLE server id. React keys off it, so an index would re-key every row on every page change. */
  readonly getRowId: (row: TData) => string;
  /**
   * `'error'` REPLACES the rows, which is right only when there are none to keep. A refetch that
   * failed with a correct page still on screen is not this state — leave `status` at `'success'` and
   * render the failure beside the table, or the user loses data they were reading to a banner about a
   * request they did not make.
   */
  readonly status: ServerDataTableStatus;
  /** The rejected query's error. Read for `error_class` only — never for the envelope's `message`. */
  readonly error?: unknown;
  /** A refetch with data already on screen. Shows a 2px bar; it never blanks correct content. */
  readonly isFetching?: boolean;
  readonly onRetry?: () => void;
  /**
   * Names the role this collection needs. Supplied ⇒ an `authorization` failure renders the FORBIDDEN
   * state naming the role rather than a generic error. Admin surfaces answer 403 and say so; a public
   * surface answers 404 and reveals nothing, which is why there is no public counterpart.
   */
  readonly requiredRole?: string;
  readonly organizationName?: string;
  /** Sort, page and filter state — all of it, from one place. See `useTableParams`. */
  readonly view: TableParamsResult;
  /** FIRST-RUN empty: the caller's copy, glyph and primary action (`<EmptyState>`). */
  readonly emptyState: ReactNode;
  /** FILTERED empty: restates what was asked for, so a too-narrow filter reads as a filter. */
  readonly describeFilter?: ReactNode;
  /** The filter bar, the search field, or the selection bar. Rendered at every width. */
  readonly header?: ReactNode;
  readonly className?: string;
}

export function ServerDataTable<TData extends RowData>({
  caption,
  columns,
  rows,
  rowCount,
  getRowId,
  status,
  error,
  isFetching = false,
  onRetry,
  requiredRole,
  organizationName,
  view,
  emptyState,
  describeFilter,
  header,
  className,
}: ServerDataTableProps<TData>) {
  const table = useTable({
    features: serverTableFeatures,
    columns,
    // `rows ?? []` would be a fresh identity every render, invalidating the core row model and
    // re-rendering every row on unrelated state changes. `EMPTY_ROWS` is one module-scope array.
    data: rows ?? EMPTY_ROWS,
    getRowId,
    manualSorting: true,
    manualPagination: true,
    // No "unsorted" state: offset pagination over an unordered result set may repeat a row on page 2
    // that page 1 already showed, because PostgreSQL guarantees no ordering without ORDER BY. Three
    // clicks return to ascending instead of clearing the sort.
    enableSortingRemoval: false,
    rowCount,
    state: { sorting: view.sorting, pagination: view.pagination },
    onSortingChange: view.onSortingChange,
    onPaginationChange: view.onPaginationChange,
  });

  const modelRows = table.getRowModel().rows;
  const isEmpty = status === 'success' && modelRows.length === 0;

  return (
    <DataTableSurface className={className}>
      {header ? <DataTableHeaderBar>{header}</DataTableHeaderBar> : null}
      {/* Directly under the card header, and only once there is something to keep on screen. A
          refetch is not a skeleton (states.md). */}
      {isFetching && status !== 'pending' ? <RefetchIndicator /> : null}

      {status === 'pending' ? <TableSkeleton columns={columns.length} /> : null}

      {status === 'error' ? (
        <TableErrorState
          error={error}
          caption={caption}
          onRetry={onRetry}
          requiredRole={requiredRole}
          organizationName={organizationName}
        />
      ) : null}

      {isEmpty ? (
        <TableEmptyState
          view={view}
          emptyState={emptyState}
          describeFilter={describeFilter}
        />
      ) : null}

      {status === 'success' && modelRows.length > 0 ? (
        <>
          <DataTableScroller>
            <Table>
              <caption className="sr-only">{caption}</caption>
              <TableHeader>
                {table.getHeaderGroups().map((headerGroup) => (
                  <TableRow key={headerGroup.id} className="bg-card-inset hover:bg-card-inset">
                    {headerGroup.headers.map((tableHeader) => (
                      <SortableHeaderCell
                        key={tableHeader.id}
                        header={tableHeader}
                        sortedId={view.params.sort.id}
                        sortedDesc={view.params.sort.desc}
                      >
                        <table.FlexRender header={tableHeader} />
                      </SortableHeaderCell>
                    ))}
                  </TableRow>
                ))}
              </TableHeader>
              <TableBody>
                {modelRows.map((row) => (
                  <TableRow key={row.id} className="h-13">
                    {row.getAllCells().map((cell) => (
                      <TableCell
                        key={cell.id}
                        className={cn(
                          'px-card-pad-md',
                          cell.column.columnDef.meta?.align === 'end' && 'text-right',
                        )}
                      >
                        <table.FlexRender cell={cell} />
                      </TableCell>
                    ))}
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </DataTableScroller>

          {/* The same rows, the same cells, the same column definitions — one data source. */}
          <DataTableCards className="p-card-pad-sm">
            {modelRows.map((row) => {
              const cells = row.getAllCells();
              const titleCell = cells.find((cell) => cardRole(cell) === 'title') ?? cells[0];
              const fieldCells = cells.filter(
                (cell) => cell !== titleCell && cardRole(cell) === 'field',
              );
              const actionCells = cells.filter((cell) => cardRole(cell) === 'action');

              return (
                <DataTableCard
                  key={row.id}
                  title={titleCell ? <table.FlexRender cell={titleCell} /> : null}
                  actions={actionCells.map((cell) => (
                    <table.FlexRender key={cell.id} cell={cell} />
                  ))}
                >
                  {fieldCells.map((cell) => (
                    <DataTableCardField key={cell.id} label={cardLabel(cell)}>
                      <table.FlexRender cell={cell} />
                    </DataTableCardField>
                  ))}
                </DataTableCard>
              );
            })}
          </DataTableCards>

          <DataTableFooterBar>
            <TablePager
              pageIndex={view.pagination.pageIndex}
              pageSize={view.pagination.pageSize}
              pageSizes={view.pageSizes}
              rowsOnPage={modelRows.length}
              rowCount={rowCount}
              onPageSize={(size) => table.setPageSize(size)}
              onPrevious={() => table.previousPage()}
              onNext={() => table.nextPage()}
            />
          </DataTableFooterBar>
        </>
      ) : null}
    </DataTableSurface>
  );
}

/** `'field'` is the default: a column that says nothing about the card layout is a labelled pair. */
function cardRole<TData extends RowData>(cell: Cell<ServerTableFeatures, TData, unknown>) {
  return cell.column.columnDef.meta?.card ?? 'field';
}

function cardLabel<TData extends RowData>(cell: Cell<ServerTableFeatures, TData, unknown>): string {
  const { meta, header } = cell.column.columnDef;
  // A header that is a render function (an icon, a visually hidden label) has no text to reuse, and
  // an unlabelled <dd> is a value nobody can identify. `cardLabel` is where that column says so.
  return meta?.cardLabel ?? (typeof header === 'string' ? header : cell.column.id);
}

/**
 * A sortable column header.
 *
 * THE INTERACTIVE ELEMENT IS A `<button>` INSIDE THE `<th>`, not an `onClick` on the `<th>` — a cell
 * with a click handler is not focusable, not activatable by Enter or Space, and invisible to assistive
 * technology (`kb-ui-accessibility` keyboard failure 1). Direction is announced through `aria-sort` on
 * the cell; the chevron is the visible second channel and it occupies its slot at all times, or the
 * whole header row shifts on the first sort (P6).
 *
 * DIRECTION COMES FROM THE `sortedId`/`sortedDesc` PROPS RATHER THAN FROM `column.getIsSorted()`.
 * Builder-pattern reads hide their state dependency from React Compiler, which then memoizes an
 * extracted header component against the stable `column` reference and leaves the arrow pointing the
 * wrong way in production builds only (`tanstack-query-table` gotcha 8). Passing the value makes the
 * dependency ordinary.
 */
function SortableHeaderCell<TData extends RowData>({
  header,
  sortedId,
  sortedDesc,
  children,
}: {
  readonly header: Header<ServerTableFeatures, TData, unknown>;
  readonly sortedId: string;
  readonly sortedDesc: boolean;
  readonly children: ReactNode;
}) {
  const canSort = header.column.getCanSort();
  const isSorted = canSort && header.column.id === sortedId;
  const alignEnd = header.column.columnDef.meta?.align === 'end';

  const cellClass = cn(
    'bg-card-inset px-card-pad-md text-caption font-medium text-muted-foreground uppercase',
    alignEnd && 'text-right',
  );

  if (!canSort) {
    return (
      <TableHead scope="col" className={cellClass}>
        {children}
      </TableHead>
    );
  }

  return (
    <TableHead
      scope="col"
      // `none` on the other sortable columns is deliberate: it tells a screen-reader user the column
      // CAN be sorted and currently is not, which an omitted attribute does not.
      aria-sort={isSorted ? (sortedDesc ? 'descending' : 'ascending') : 'none'}
      className={cellClass}
    >
      <button
        type="button"
        onClick={header.column.getToggleSortingHandler()}
        className={cn(
          // The pressed state is mandatory rather than optional: Tailwind v4 wraps `hover:` in
          // `@media (hover: hover)`, so a touch device gets no feedback at all without `active:`.
          '-mx-1 inline-flex items-center gap-1 rounded-md px-1 py-0.5 text-caption uppercase',
          'transition-colors duration-(--dur-1) hover:text-foreground active:bg-accent',
          'pointer-coarse:min-h-11',
          alignEnd && 'flex-row-reverse',
        )}
      >
        {children}
        <SortGlyph active={isSorted} desc={sortedDesc} />
      </button>
    </TableHead>
  );
}

function SortGlyph({ active, desc }: { readonly active: boolean; readonly desc: boolean }) {
  if (!active) return <ChevronsUpDownIcon aria-hidden className="size-3 opacity-50" />;
  return desc ? (
    <ChevronDownIcon aria-hidden className="size-3" />
  ) : (
    <ChevronUpIcon aria-hidden className="size-3" />
  );
}

/**
 * ERROR, and the FORBIDDEN branch inside it.
 *
 * The branch is on `error_class`, never on an HTTP status: one class renders two statuses by surface,
 * and status-driven logic reads an `authorization` 404 as "absent, so create it" (`kb-error-taxonomy`).
 * Without a `requiredRole` there is no honest forbidden sentence to write — naming no role would be a
 * dead end — so it falls through to the class-mapped error, which is still correct.
 */
function TableErrorState({
  error,
  caption,
  onRetry,
  requiredRole,
  organizationName,
}: {
  readonly error: unknown;
  readonly caption: string;
  readonly onRetry?: () => void;
  readonly requiredRole?: string;
  readonly organizationName?: string;
}) {
  const forbidden =
    requiredRole !== undefined &&
    error instanceof KbError &&
    error.error_class === 'authorization';

  if (forbidden) {
    return <ForbiddenState requiredRole={requiredRole} organizationName={organizationName} />;
  }

  return (
    <div className="p-card-pad-md">
      {/* The title says WHAT failed; the class-mapped sentence says why. `ErrorState` gates the retry
          affordance on the envelope's `retryable`, so an unknown class gets no button. */}
      <ErrorState error={error} title={`${caption} could not be loaded`} onRetry={onRetry} />
    </div>
  );
}

/**
 * THE EMPTY SPLIT, decided in one place from a value derived out of the URL rather than from a boolean
 * a caller passes — because the failure mode is not "the split is missing", it is "the split exists
 * and somebody passed the wrong value".
 *
 * Filter first, then page: a URL carrying both a filter and an out-of-range page is a filter that
 * narrowed the set, and "clear filters" is the action that actually restores rows.
 */
function TableEmptyState({
  view,
  emptyState,
  describeFilter,
}: {
  readonly view: TableParamsResult;
  readonly emptyState: ReactNode;
  readonly describeFilter?: ReactNode;
}) {
  if (view.isFiltered) {
    return (
      <FilteredEmptyState
        describeFilter={describeFilter ?? 'No rows match the current filters.'}
        onClear={view.clearFilters}
      />
    );
  }

  if (view.params.pageIndex > 0) {
    return <PageOutOfRangeState onFirstPage={view.goToFirstPage} />;
  }

  return <>{emptyState}</>;
}

/**
 * The pagination footer: page size on the left, range centred, prev/next on the right (P6).
 *
 * IT TAKES VALUES, NOT THE TABLE. Every number here is arithmetic the caller already has, and reading
 * `table.getCanNextPage()` from an extracted component is the builder-read shape that memoizes wrong.
 *
 * The range is an `<output>`, whose implicit `role="status"` announces the new range politely after a
 * page change — a user-initiated, infrequent update, which is what a live region is for. It is not on
 * the rows, because a five-second poll that announces itself every five seconds is a reason to stop
 * using the product.
 */
function TablePager({
  pageIndex,
  pageSize,
  pageSizes,
  rowsOnPage,
  rowCount,
  onPageSize,
  onPrevious,
  onNext,
}: {
  readonly pageIndex: number;
  readonly pageSize: number;
  readonly pageSizes: readonly number[];
  readonly rowsOnPage: number;
  readonly rowCount: number | undefined;
  readonly onPageSize: (size: number) => void;
  readonly onPrevious: () => void;
  readonly onNext: () => void;
}) {
  const selectId = useId();

  if (rowCount === undefined) {
    // UNKNOWN TOTAL. Not zero, not "page 1 of 1": both are statements, and we do not have one yet.
    return (
      <div aria-busy="true" className="flex w-full items-center justify-between gap-2">
        <Skeleton className="h-8 w-28" />
        <Skeleton className="h-4 w-32" />
        <Skeleton className="h-8 w-20" />
      </div>
    );
  }

  const format = new Intl.NumberFormat();
  const first = pageIndex * pageSize + 1;
  // THE LABEL AND THE BUTTON ARE DERIVED FROM DIFFERENT SOURCES ON PURPOSE.
  //
  // `pageIndex` and `pageSize` come from the URL and move SYNCHRONOUSLY on a click. `rowsOnPage` is
  // `modelRows.length`, and under TanStack Query's `placeholderData` that is still the PREVIOUS
  // page's row count until the fetch lands. Mixing the two gives a window that briefly describes a
  // page that does not exist: paging from 5 to a final page 6 of 137 rows renders "126–150 of 137".
  //
  //   - `last` is clamped to the total, so the label can be a page behind but never nonsense.
  //   - `canNext` is derived from the URL and the envelope total ALONE, so the placeholder window
  //     cannot reach it. Deriving it from `last` inherited the skew and could offer a Next that
  //     walks past the end (or, on a shrinking page, refuse one that exists).
  const last = Math.min(first + rowsOnPage - 1, rowCount);
  const canPrevious = pageIndex > 0;
  const canNext = (pageIndex + 1) * pageSize < rowCount;

  return (
    <>
      <div className="flex items-center gap-2">
        <Label htmlFor={selectId} className="text-sm text-muted-foreground">
          Rows per page
        </Label>
        <Select value={String(pageSize)} onValueChange={(next) => onPageSize(Number(next))}>
          <SelectTrigger id={selectId} size="sm" className="w-20">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {pageSizes.map((size) => (
              <SelectItem key={size} value={String(size)}>
                {format.format(size)}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      <output className="text-sm text-muted-foreground">
        {`${format.format(first)}–${format.format(last)} of ${format.format(rowCount)}`}
      </output>

      <div className="flex items-center gap-2">
        <Button
          variant="outline"
          size="icon-sm"
          aria-label="Previous page"
          disabled={!canPrevious}
          onClick={onPrevious}
        >
          <ChevronLeftIcon aria-hidden />
        </Button>
        <Button
          variant="outline"
          size="icon-sm"
          aria-label="Next page"
          disabled={!canNext}
          onClick={onNext}
        >
          <ChevronRightIcon aria-hidden />
        </Button>
      </div>
    </>
  );
}
