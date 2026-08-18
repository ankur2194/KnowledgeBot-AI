import {
  AlertTriangleIcon,
  CheckCircle2Icon,
  CircleDashedIcon,
  LoaderIcon,
  TrendingDownIcon,
  TrendingUpIcon,
  XCircleIcon,
  type LucideIcon,
} from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

/**
 * P8. The status vocabulary is CLOSED and maps to the source lifecycle plus the error taxonomy —
 * do not invent a label.
 *
 * THE GLYPH IS NOT DECORATION. It is the second channel that makes the pill readable in greyscale
 * and under CVD, and a pill carrying colour alone is a finding, not a style choice
 * (kb-ui-accessibility, *Colour independence*). It is supplied by this table rather than by the
 * caller precisely so it cannot be left off.
 */
export type StatusKind = 'pending' | 'running' | 'ready' | 'degraded' | 'failed' | 'disabled';

const STATUS: Readonly<
  Record<StatusKind, { readonly variant: 'default' | 'info' | 'success' | 'warning' | 'destructive'; readonly glyph: LucideIcon; readonly spin?: boolean }>
> = Object.freeze({
  pending: { variant: 'default', glyph: CircleDashedIcon },
  running: { variant: 'info', glyph: LoaderIcon, spin: true },
  ready: { variant: 'success', glyph: CheckCircle2Icon },
  degraded: { variant: 'warning', glyph: AlertTriangleIcon },
  failed: { variant: 'destructive', glyph: XCircleIcon },
  disabled: { variant: 'default', glyph: CircleDashedIcon },
});

export function StatusPill({
  status,
  label,
  className,
}: {
  readonly status: StatusKind;
  /** Sentence case. The word is the third channel and is never omitted. */
  readonly label: string;
  readonly className?: string;
}) {
  const { variant, glyph: Glyph, spin } = STATUS[status];

  return (
    <Badge variant={variant} data-status={status} className={className}>
      <Glyph
        aria-hidden
        className={cn(spin && 'animate-spin motion-reduce:animate-none')}
      />
      {label}
    </Badge>
  );
}

/**
 * P4's delta pill, and the reason it is its own component: THE COLOUR COMES FROM POLARITY, NOT FROM
 * THE SIGN.
 *
 * "Up" is not "good". Cost, latency, error rate, refusal rate and quota consumption are all WORSE
 * when they rise, so the tile declares `higherIsBetter` and the pill derives its colour from
 * `polarity × sign`. The ARROW still derives from the sign — which is what makes a bad increase read
 * correctly as a red pill with an up arrow, and what makes the pill legible without colour at all.
 *
 * Zero change is `--tone-slate` with an em dash, not a green 0%. A tile with no comparison period
 * renders no delta at all rather than "+0%".
 */
export function DeltaPill({
  value,
  higherIsBetter,
  caption,
  className,
}: {
  /** The signed change, as a fraction (0.124 renders "+12.4%"). */
  readonly value: number;
  /** REQUIRED, never defaulted. Getting this wrong paints bad news green. */
  readonly higherIsBetter: boolean;
  readonly caption?: string;
  readonly className?: string;
}) {
  if (value === 0) {
    return (
      <span className={cn('inline-flex items-center gap-1', className)}>
        <Badge variant="default">
          <span aria-hidden>—</span>
          <span className="sr-only">No change</span>
        </Badge>
        {caption ? <span className="text-caption text-muted-foreground">{caption}</span> : null}
      </span>
    );
  }

  const rising = value > 0;
  const good = rising === higherIsBetter;
  const Glyph = rising ? TrendingUpIcon : TrendingDownIcon;
  const percent = `${rising ? '+' : '−'}${Math.abs(value * 100).toFixed(1)}%`;

  return (
    <span className={cn('inline-flex items-center gap-1', className)}>
      <Badge variant={good ? 'success' : 'destructive'}>
        <Glyph aria-hidden />
        <span className="tabular-nums">{percent}</span>
      </Badge>
      {caption ? <span className="text-caption text-muted-foreground">{caption}</span> : null}
    </span>
  );
}
