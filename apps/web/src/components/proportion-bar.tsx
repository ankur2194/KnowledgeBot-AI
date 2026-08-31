import { useId } from 'react';

import { cn } from '@/lib/utils';

/**
 * A stacked proportion bar, in INLINE SVG.
 *
 * ── WHY THIS AND NOT A SPARKLINE, AND WHY NOT A CHART LIBRARY ───────────────────────────────────
 * `apps/web` has no chart dependency and the dependency discipline here is exact pins with no
 * carets, so adding one for two bars would be the largest thing on the page by a wide margin. Inline
 * SVG for a fixed geometry costs nothing and is the whole of what these two figures need.
 *
 * A SPARKLINE WOULD NEED A TIME SERIES AND THE ENDPOINT PUBLISHES NONE. `AnalyticsResource` is
 * eleven aggregates over ONE resolved window — `conversations`, `messages`, the percentiles, the
 * outcome counts — with no per-day array anywhere on the shape. So there is nothing to draw a line
 * through, and a "sparkline" over a single point would be a decoration that implies a trend nobody
 * measured. What the data DOES support is a proportion, which is this.
 *
 * ── COLOUR IS NEVER THE ONLY CHANNEL ────────────────────────────────────────────────────────────
 * Each segment carries a LABEL and a VALUE in the legend beneath, in the same order as the bar, and
 * the whole figure carries `role="img"` with a descriptive name. `kb-ui-accessibility` requires a
 * real data-table alternative for a chart; the legend IS that table here — three rows of
 * label/value — rather than a second rendering of the same numbers somewhere else, because a
 * two-segment bar has no structure a `<table>` would add.
 *
 * ── EVERY VALUE IS A TOKEN ──────────────────────────────────────────────────────────────────────
 * The fills are `--chart-1`…`--chart-6` through Tailwind utilities, which is the ordered series
 * palette. Adjacent members are spaced >= 0.06 OKLCH in lightness, which is the property that
 * survives greyscale and CVD, and the set is CLOSED at six — a seventh category becomes "Other".
 */
export interface ProportionSegment {
  readonly label: string;
  readonly value: number;
  /**
   * TWO WHOLE CLASS STRINGS, because the bar segment and the legend dot are different properties on
   * different elements — `fill-chart-3` on an SVG `<rect>` and `bg-chart-3` on a `<span>`.
   *
   * PASSED WHOLE, NEVER COMPOSED. Tailwind's scanner is a plain-text pass over source files rather
   * than an evaluator, so `` `fill-chart-${n}` `` is a name that never appears literally and
   * therefore never exists — the rect renders with no fill, with no error and no build warning.
   * `CHART_SERIES` below is the closed lookup that keeps both spellings literal.
   */
  readonly fill: string;
  readonly swatch: string;
}

/**
 * THE ORDERED SERIES PALETTE, as literal class strings, CLOSED AT SIX.
 *
 * Six is the discrimination limit for low-chroma tints and the set is closed for that reason: a
 * seventh category becomes "Other" with a breakdown, never a seventh colour. Adjacent members are
 * spaced >= 0.06 OKLCH in lightness, which is the property that survives greyscale and colour-vision
 * deficiency — so the ORDER is load-bearing and a caller must take them from the front rather than
 * picking favourites.
 */
export const CHART_SERIES: readonly { readonly fill: string; readonly swatch: string }[] = [
  { fill: 'fill-chart-1', swatch: 'bg-chart-1' },
  { fill: 'fill-chart-2', swatch: 'bg-chart-2' },
  { fill: 'fill-chart-3', swatch: 'bg-chart-3' },
  { fill: 'fill-chart-4', swatch: 'bg-chart-4' },
  { fill: 'fill-chart-5', swatch: 'bg-chart-5' },
  { fill: 'fill-chart-6', swatch: 'bg-chart-6' },
];

export function ProportionBar({
  caption,
  segments,
  empty,
  className,
}: {
  /** The figure's accessible name. It says what is being divided, not "chart". */
  readonly caption: string;
  readonly segments: readonly ProportionSegment[];
  /** What to render when every segment is zero — which is a real state and not a loading one. */
  readonly empty: string;
  readonly className?: string;
}) {
  const titleId = useId();
  const total = segments.reduce((sum, segment) => sum + segment.value, 0);

  if (total === 0) {
    return (
      <div className={cn('flex flex-col gap-2', className)}>
        <p className="text-caption text-muted-foreground uppercase">{caption}</p>
        <p className="text-sm text-muted-foreground">{empty}</p>
      </div>
    );
  }

  /**
   * THE GEOMETRY IS COMPUTED BEFORE THE JSX, NOT ACCUMULATED INSIDE IT.
   *
   * A `let offset = 0` mutated inside `segments.map(...)` is the natural way to write a stacked bar
   * and it is an ERROR under `react-hooks/immutability`: React Compiler may memoize the map's
   * callback, and a running total captured across renders is the shape that produces a bar whose
   * segments drift out of alignment only in a production build. `reduce` makes the offset a value
   * rather than a side effect.
   */
  const placed = segments.reduce<
    { readonly running: number; readonly bars: readonly { readonly x: number; readonly width: number; readonly segment: ProportionSegment }[] }
  >(
    (accumulator, segment) => ({
      running: accumulator.running + (segment.value / total) * 100,
      bars: [
        ...accumulator.bars,
        { x: accumulator.running, width: (segment.value / total) * 100, segment },
      ],
    }),
    { running: 0, bars: [] },
  ).bars;

  return (
    <div className={cn('flex flex-col gap-2', className)}>
      <p className="text-caption text-muted-foreground uppercase">{caption}</p>

      <svg
        // `role="img"` plus a real name: without it a screen reader announces nothing at all for an
        // inline SVG, and with a name but no role it announces the raw markup in some readers.
        role="img"
        aria-labelledby={titleId}
        viewBox="0 0 100 8"
        preserveAspectRatio="none"
        className="h-2 w-full overflow-hidden rounded-full bg-card-inset"
      >
        <title id={titleId}>{`${caption}: ${segments
          .map((segment) => `${segment.label} ${segment.value}`)
          .join(', ')}`}</title>
        {placed.map(({ x, width, segment }) =>
          // A zero-width segment renders NOTHING rather than a hairline: a `<rect width="0">` is
          // still a node, and a legend row for it is the honest place to say the count is zero.
          width === 0 ? null : (
            <rect key={segment.label} x={x} y={0} width={width} height={8} className={segment.fill} />
          ),
        )}
      </svg>

      {/* THE LEGEND IS THE DATA TABLE. Same order as the bar, label and value in text, so the figure
          is readable with no colour at all. */}
      <ul className="flex flex-wrap gap-x-4 gap-y-1">
        {segments.map((segment) => (
          <li key={segment.label} className="flex items-center gap-1.5 text-sm">
            <span aria-hidden className={cn('size-2 rounded-full', segment.swatch)} />
            <span className="text-muted-foreground">{segment.label}</span>
            <span className="tabular-nums">{segment.value}</span>
          </li>
        ))}
      </ul>
    </div>
  );
}
