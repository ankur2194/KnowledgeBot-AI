import { ArrowRightIcon, type LucideIcon } from 'lucide-react';
import Link from 'next/link';

import { TONE, type ToneName } from '@/components/tone';
import { cn } from '@/lib/utils';

/**
 * P7 · E3. A pastel category tile: `--tone-{x}-surface`, `--radius-xl`, `--card-pad-md`, NO SHADOW —
 * a tone tile is a wash on the plane it sits on, not a raised object.
 *
 * `edged` is only for a tile sitting on `--card` rather than on `--canvas`, where the ~0.04
 * lightness step is not enough on its own.
 *
 * There is NO MUTED TEXT ON A TONE SURFACE. `--muted-foreground` is contrast-checked against
 * `--card` and `--canvas`, not against a tint; the paired token is the tone's own `-foreground`, and
 * inventing a muted variant with opacity puts you back in the untestable-colour hole.
 *
 * The tone comes from a stable key through `TONE` — never an array index, never a template literal.
 */
export function ToneTile({
  tone,
  glyph: Glyph,
  title,
  supporting,
  count,
  href,
  edged = false,
  className,
}: {
  readonly tone: ToneName;
  readonly glyph?: LucideIcon;
  readonly title: string;
  readonly supporting?: string;
  /** Pre-formatted. Rendered `tabular-nums` because it can change. */
  readonly count?: string;
  readonly href?: string;
  readonly edged?: boolean;
  readonly className?: string;
}) {
  const classes = TONE[tone];

  return (
    <div
      className={cn(
        'relative flex min-h-32 flex-col gap-1 rounded-xl p-card-pad-md',
        classes.surface,
        edged && classes.edge,
        className,
      )}
    >
      {Glyph ? <Glyph aria-hidden className="size-5" strokeWidth={1.75} /> : null}
      <p className="text-base font-semibold">{title}</p>
      {supporting ? <p className="text-sm">{supporting}</p> : null}
      {count ? <p className="mt-auto text-metric tabular-nums">{count}</p> : null}
      {href ? (
        // The affordance is a real link with a real href, and its accessible name says where it
        // goes — an unlabelled arrow is the icon-only control kb-ui-accessibility rejects.
        <Link
          href={href}
          aria-label={`Open ${title}`}
          className="absolute right-card-pad-md bottom-card-pad-md flex size-8 items-center justify-center rounded-full bg-foreground text-card transition-transform duration-(--dur-1) ease-out active:translate-y-px"
        >
          <ArrowRightIcon aria-hidden className="size-4" />
        </Link>
      ) : null}
    </div>
  );
}
