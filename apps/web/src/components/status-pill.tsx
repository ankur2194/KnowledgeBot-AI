import {
  AlertTriangleIcon,
  CheckCircle2Icon,
  CircleDashedIcon,
  CircleDotIcon,
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
export type StatusKind =
  | 'pending'
  | 'running'
  | 'info'
  | 'ready'
  | 'degraded'
  | 'failed'
  | 'disabled';

/**
 * ── `info` IS THE SEVENTH KIND, AND IT EXISTS BECAUSE `running` COULD NOT BE BORROWED ───────────
 *
 * There are TWO informational states, not one, and until this entry landed the vocabulary only had
 * the moving one. `running` is info-coloured and its glyph SPINS, which is correct for a stage that
 * is genuinely in flight and wrong for anything that merely *is* the info tone: an endless spinner
 * on a static list row reads as "this page is stuck", so the caller's only other option was the
 * slate bucket. That is what `features/bots/api.ts` did for `testing`, and it left three bot
 * statuses (`draft`, `testing`, `archived`) sharing one colour AND one glyph, distinguished by
 * their word alone — colour-independence-compliant and weak to scan, which is a real cost on a
 * table an operator reads by sweeping the status column.
 *
 * So `info` is `running` minus the motion: same `--info-soft` fill, a STILL glyph.
 *
 * WHY `CircleDotIcon` AND NOT `InfoIcon`. A circled lowercase "i" means "there is an explanation
 * here" — it is the affordance an `<Alert variant="info">` carries, and putting it on a lifecycle
 * pill states the wrong thing twice over: every pill is information, and nothing on this one is
 * expandable. A filled centre inside a ring reads as "marked, and not moving", which is what a
 * trialled-but-unpublished thing is. It is also distinguishable in greyscale from all four other
 * still glyphs (dashed ring, tick, triangle, cross), which is the whole reason the glyph is
 * supplied by this table rather than by the caller.
 *
 * WHAT THIS DOES NOT FIX, said out loud rather than left to be rediscovered: `pending` and
 * `disabled` still share `CircleDashedIcon` and the neutral fill, so `draft` and `archived` remain
 * separated by their word alone. Repairing THAT means giving `disabled` its own glyph, which is a
 * change to a bucket four other features already render (`connectionStatusKind`,
 * `invitationStatusKind`, `membershipStatusKind`, and the model catalogue) — a wider blast radius
 * than the one entry this repair was scoped to. Reported, not reached for.
 */
const STATUS: Readonly<
  Record<StatusKind, { readonly variant: 'default' | 'info' | 'success' | 'warning' | 'destructive'; readonly glyph: LucideIcon; readonly spin?: boolean }>
> = Object.freeze({
  pending: { variant: 'default', glyph: CircleDashedIcon },
  running: { variant: 'info', glyph: LoaderIcon, spin: true },
  info: { variant: 'info', glyph: CircleDotIcon },
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
