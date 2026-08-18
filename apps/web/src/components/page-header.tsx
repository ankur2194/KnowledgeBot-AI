import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

/**
 * P3. A `--text-h1` title, an optional ONE-SENTENCE description in `--muted-foreground`, and a
 * right-aligned action cluster.
 *
 * THE DESCRIPTION SAYS WHAT THE PAGE IS FOR. It is not a second title and it is not two sentences —
 * "Documents, spreadsheets and crawled sites this bot can answer from", not "Manage your sources".
 *
 * AT MOST ONE `--primary` ACTION. Rule 3 of the design language: if two things on a screen compete
 * for "most saturated", one of them is wrong. More than three actions means an overflow menu.
 *
 * FILTERS GO UNDER THE HEADER, in their own row (`children`), so they can wrap on a narrow screen
 * without disturbing the title.
 */
export function PageHeader({
  title,
  titleId,
  description,
  actions,
  children,
  className,
}: {
  readonly title: ReactNode;
  /** Wired to the section's `aria-labelledby`, so the page has one real `h1`. */
  readonly titleId?: string;
  readonly description?: ReactNode;
  readonly actions?: ReactNode;
  /** The filter row. */
  readonly children?: ReactNode;
  readonly className?: string;
}) {
  return (
    <header className={cn('mb-6 flex flex-col gap-4', className)}>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="flex min-w-0 flex-col gap-1">
          <h1 id={titleId} className="text-h1">
            {title}
          </h1>
          {description ? <p className="text-base text-muted-foreground">{description}</p> : null}
        </div>
        {actions ? <div className="flex shrink-0 items-center gap-2">{actions}</div> : null}
      </div>
      {children}
    </header>
  );
}
