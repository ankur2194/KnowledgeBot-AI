import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

/**
 * P6. The container a data table lives in: `--card`, `--radius-2xl`, `--shadow-md`, with the table
 * bleeding to the container edge so the rules run the full width.
 *
 * WHAT THIS DELIBERATELY IS NOT: a grid of divs with `role="table"`. That is a reimplementation
 * which loses row and column announcements in at least one screen reader, so the real `<table>` with
 * `<th scope>` from `components/ui/table.tsx` goes inside this.
 *
 * BELOW 768px A TABLE BECOMES A STACK OF CARDS, not a horizontal scroller. A scroller hides the
 * actions column, which is where the destructive action lives — the one that most needs to be
 * visible. `<DataTableCards>` is that layout; a surface renders one or the other by breakpoint, and
 * both from the same data.
 *
 * ── THE FOUR PIECES BELOW ARE SEPARATE EXPORTS FOR ONE REASON ────────────────────────────────────
 * `DataTableShell` gates its CHILDREN behind `md:` — correct for the table, wrong for a state. An
 * empty, error or forbidden state must render at every width, so `<ServerDataTable>` composes
 * `DataTableSurface` + the bars itself and drops the state in unconditionally. Splitting them keeps
 * ONE definition of the card surface: a second `rounded-2xl bg-card shadow-md` somewhere else is a
 * fork of the elevation ladder that no token check can see.
 */
export function DataTableSurface({
  className,
  children,
}: {
  readonly className?: string;
  readonly children: ReactNode;
}) {
  return (
    <div
      data-slot="data-table-surface"
      className={cn('overflow-hidden rounded-2xl bg-card shadow-md', className)}
    >
      {children}
    </div>
  );
}

/** Replaced wholesale by the selection bar while anything is selected — never a floating bar. */
export function DataTableHeaderBar({ children }: { readonly children: ReactNode }) {
  return <div className="flex items-center justify-between gap-2 px-card-pad-md py-3">{children}</div>;
}

/** `--card-inset`: page size on the left, range text centred, prev/next on the right. */
export function DataTableFooterBar({ children }: { readonly children: ReactNode }) {
  return (
    <div className="flex items-center justify-between gap-2 bg-card-inset px-card-pad-md py-2 text-sm">
      {children}
    </div>
  );
}

/**
 * The table scrolls INSIDE its own container; the page body never scrolls horizontally (WCAG 1.4.10).
 * Above 768px only — below it, the caller renders `<DataTableCards>`.
 */
export function DataTableScroller({ children }: { readonly children: ReactNode }) {
  return <div className="hidden overflow-x-auto md:block">{children}</div>;
}

export function DataTableShell({
  header,
  footer,
  className,
  children,
}: {
  readonly header?: ReactNode;
  readonly footer?: ReactNode;
  readonly className?: string;
  readonly children: ReactNode;
}) {
  return (
    <DataTableSurface className={className}>
      {header ? <DataTableHeaderBar>{header}</DataTableHeaderBar> : null}
      <DataTableScroller>{children}</DataTableScroller>
      {footer ? <DataTableFooterBar>{footer}</DataTableFooterBar> : null}
    </DataTableSurface>
  );
}

/**
 * The below-768px form of a table: one card per row, the primary column as the title, two or three
 * secondary columns as labelled pairs beneath.
 */
export function DataTableCards({ className, children }: { readonly className?: string; readonly children: ReactNode }) {
  return (
    <div data-slot="data-table-cards" className={cn('flex flex-col gap-3 md:hidden', className)}>
      {children}
    </div>
  );
}

export function DataTableCard({
  title,
  actions,
  children,
}: {
  readonly title: ReactNode;
  /**
   * Rendered AFTER the `<dl>`, not inside it. An action is not a labelled pair, and a `<button>`
   * between a `<dt>` and a `<dd>` is invalid content for a description list — which costs the row
   * and column relationship a screen reader was going to read out.
   */
  readonly actions?: ReactNode;
  readonly children: ReactNode;
}) {
  return (
    <div
      data-slot="data-table-card"
      className="flex flex-col gap-2 rounded-xl bg-card p-card-pad-sm shadow-sm"
    >
      <p className="text-base font-medium">{title}</p>
      <dl className="grid grid-cols-2 gap-x-3 gap-y-1">{children}</dl>
      {actions ? <div className="flex items-center justify-end gap-2 pt-1">{actions}</div> : null}
    </div>
  );
}

export function DataTableCardField({ label, children }: { readonly label: string; readonly children: ReactNode }) {
  return (
    <div className="flex flex-col">
      <dt className="text-caption text-muted-foreground uppercase">{label}</dt>
      <dd className="text-sm">{children}</dd>
    </div>
  );
}
