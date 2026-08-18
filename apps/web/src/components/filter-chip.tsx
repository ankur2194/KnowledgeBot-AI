'use client';

import { XIcon } from 'lucide-react';

import { cn } from '@/lib/utils';

/**
 * P9. A removable APPLIED filter: `--card-inset`, `--radius-full`, `--text-sm`.
 *
 * The remove button's accessible name includes WHICH filter it removes ("Remove filter: status is
 * failed"), because a row of five buttons all called "Remove" is a row of five identical
 * announcements.
 *
 * Filters, sort and page live in the URL, not in component state — that is what makes a filtered
 * view shareable, what makes it survive a reload, and what makes a customer's "no results" report
 * debuggable. The organization does NOT go in the URL; it lives in the session.
 */
export function FilterChip({
  label,
  onRemove,
  className,
}: {
  /** Reads as a statement: "Status is failed". */
  readonly label: string;
  readonly onRemove: () => void;
  readonly className?: string;
}) {
  return (
    <span
      className={cn(
        'inline-flex items-center gap-1 rounded-full bg-card-inset py-0.5 pr-1 pl-2 text-sm',
        className,
      )}
    >
      {label}
      <button
        type="button"
        onClick={onRemove}
        aria-label={`Remove filter: ${label}`}
        // The visual stays 16px; the hit area reaches 44px on a coarse pointer through padding,
        // which is what kb-ui-accessibility asks for — the VISUAL may stay small.
        className="flex size-4 items-center justify-center rounded-full text-muted-foreground transition-colors duration-(--dur-1) hover:bg-accent hover:text-foreground active:bg-accent pointer-coarse:size-11"
      >
        <XIcon aria-hidden className="size-3" />
      </button>
    </span>
  );
}
