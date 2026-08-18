import type { LucideIcon } from 'lucide-react';

import { DeltaPill } from '@/components/status-pill';
import { cn } from '@/lib/utils';

/**
 * P4. Caption above, metric, delta below — ALWAYS that order, because a row of tiles has to align on
 * the metric baseline and it cannot if the caption is sometimes two lines. The caption truncates; it
 * never wraps.
 *
 * `higherIsBetter` IS REQUIRED AND HAS NO DEFAULT. Latency, cost, error rate, refusal rate and quota
 * consumed are all `false`, and a default of `true` silently paints half the dashboard's bad news
 * green. Making it required means a new tile cannot be written without someone deciding.
 *
 * `tabular-nums` on the metric is not cosmetic: proportional digits make a polling KPI jitter
 * horizontally on every refresh, and it gets debugged as a layout-thrash bug.
 *
 * A tile with no comparison period renders NO delta — not "+0%".
 */
export function StatTile({
  caption,
  value,
  glyph: Glyph,
  delta,
  deltaCaption,
  higherIsBetter,
  className,
}: {
  readonly caption: string;
  /** Pre-formatted for the locale. This component does not know what the number means. */
  readonly value: string;
  readonly glyph?: LucideIcon;
  /** Signed fraction. Omit entirely when there is no comparison period. */
  readonly delta?: number;
  readonly deltaCaption?: string;
  readonly higherIsBetter: boolean;
  readonly className?: string;
}) {
  return (
    <div className={cn('flex flex-col gap-2 rounded-xl bg-card p-card-pad-md shadow-sm', className)}>
      <div className="flex items-start justify-between gap-2">
        <p className="truncate text-caption text-muted-foreground uppercase">{caption}</p>
        {Glyph ? <Glyph aria-hidden className="size-5 shrink-0 text-muted-foreground" /> : null}
      </div>
      <p className="text-metric tabular-nums">{value}</p>
      {delta === undefined ? null : (
        <DeltaPill value={delta} higherIsBetter={higherIsBetter} caption={deltaCaption} />
      )}
    </div>
  );
}
