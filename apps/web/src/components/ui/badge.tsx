import * as React from 'react';
import { cva, type VariantProps } from 'class-variance-authority';
import { Slot } from 'radix-ui';

import { cn } from '@/lib/utils';

/**
 * The pill geometry (kb-ui-patterns P8): `--radius-full`, `--text-caption`, `--space-2` horizontal
 * and `--space-0-5` vertical.
 *
 * A badge that carries a STATUS should be `<StatusPill>` (components/status-pill.tsx) rather than
 * this — the glyph is mandatory there, and making it a separate component is what stops it being
 * forgotten. This is the plain container.
 */
const badgeVariants = cva(
  [
    'inline-flex w-fit shrink-0 items-center justify-center gap-1 overflow-hidden',
    'rounded-full px-2 py-0.5 text-caption font-medium whitespace-nowrap',
    'transition-[color,background-color] duration-(--dur-1) ease-out',
    "[&>svg]:pointer-events-none [&>svg:not([class*='size-'])]:size-3",
  ],
  {
    variants: {
      variant: {
        // Neutral, and the DEFAULT — a count or a label on a card header, on the recessed strip so
        // it does not compete with anything (P2: never the accent, which is already spent on the
        // active state).
        default: 'bg-card-inset text-muted-foreground',
        // The accent, for a genuinely selected or active thing. One per screen.
        primary: 'bg-primary-soft text-primary-soft-foreground',
        success: 'bg-success-soft text-success-soft-foreground',
        warning: 'bg-warning-soft text-warning-soft-foreground',
        destructive: 'bg-destructive-soft text-destructive-soft-foreground',
        info: 'bg-info-soft text-info-soft-foreground',
        // A hairline edge instead of a fill, for a chip on an already-tinted surface.
        outline: 'text-foreground shadow-hairline',
      },
    },
    defaultVariants: {
      variant: 'default',
    },
  },
);

function Badge({
  className,
  variant,
  asChild = false,
  ...props
}: React.ComponentProps<'span'> & VariantProps<typeof badgeVariants> & { asChild?: boolean }) {
  const Comp = asChild ? Slot.Root : 'span';

  return (
    <Comp data-slot="badge" className={cn(badgeVariants({ variant }), className)} {...props} />
  );
}

export { Badge, badgeVariants };
