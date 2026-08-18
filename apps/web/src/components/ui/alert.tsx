import * as React from 'react';
import { cva, type VariantProps } from 'class-variance-authority';
import {
  AlertTriangleIcon,
  CheckCircle2Icon,
  InfoIcon,
  XCircleIcon,
  type LucideIcon,
} from 'lucide-react';

import { cn } from '@/lib/utils';

/**
 * The INLINE BANNER (kb-ui-patterns P12): a `{status}-soft` surface with a 3px `{status}` left rule,
 * a glyph, a title and a body. It stays until the condition clears.
 *
 * WHEN TO USE THIS RATHER THAN A TOAST: anything that needs a decision, a retry or a correction, and
 * anything describing a persistent condition ("This bot's provider credential expired"). A toast is
 * for an outcome the user does NOT need to act on. An error the user must act on is never a toast —
 * it disappears, it is unreachable by keyboard once it does, and it is the single most common
 * accessibility complaint about dashboards.
 *
 * THE GLYPH IS NOT DECORATION. It is the non-colour channel that makes the banner readable in
 * greyscale and under CVD, and a status carried by colour alone is a finding
 * (kb-ui-accessibility, *Colour independence*). It is supplied by the variant rather than by the
 * caller so it cannot be omitted.
 */
const alertVariants = cva(
  [
    'relative grid w-full grid-cols-[calc(var(--spacing)*4)_1fr] items-start gap-x-3 gap-y-0.5',
    'rounded-lg border-l-[3px] px-4 py-3 text-base',
    '[&>svg]:size-4 [&>svg]:translate-y-0.5',
  ],
  {
    variants: {
      variant: {
        // Neutral: a statement of fact with no outcome attached. The recessed strip, not a status.
        default: 'border-l-border bg-card-inset text-foreground',
        info: 'border-l-info bg-info-soft text-info-soft-foreground',
        success: 'border-l-success bg-success-soft text-success-soft-foreground',
        warning: 'border-l-warning bg-warning-soft text-warning-soft-foreground',
        destructive:
          'border-l-destructive bg-destructive-soft text-destructive-soft-foreground',
      },
    },
    defaultVariants: {
      variant: 'default',
    },
  },
);

const GLYPH: Record<NonNullable<VariantProps<typeof alertVariants>['variant']>, LucideIcon> = {
  default: InfoIcon,
  info: InfoIcon,
  success: CheckCircle2Icon,
  warning: AlertTriangleIcon,
  destructive: XCircleIcon,
};

function Alert({
  className,
  variant = 'default',
  children,
  ...props
}: React.ComponentProps<'div'> & VariantProps<typeof alertVariants>) {
  const Glyph = GLYPH[variant ?? 'default'];

  return (
    <div data-slot="alert" role="alert" className={cn(alertVariants({ variant }), className)} {...props}>
      {/* Decorative: the variant is already stated by the title and body text, so announcing the
          glyph would repeat it. Its job is visual, for the reader who cannot use the colour. */}
      <Glyph aria-hidden />
      {children}
    </div>
  );
}

function AlertTitle({ className, ...props }: React.ComponentProps<'div'>) {
  return (
    <div
      data-slot="alert-title"
      className={cn('col-start-2 min-h-4 font-medium', className)}
      {...props}
    />
  );
}

/**
 * NO `--muted-foreground` HERE. It is contrast-checked against `--card` and `--canvas`, not against
 * a tinted `-soft` surface; the paired text token for a soft surface is its own
 * `-soft-foreground`, which the variant already sets on the container and this inherits.
 */
function AlertDescription({ className, ...props }: React.ComponentProps<'div'>) {
  return (
    <div
      data-slot="alert-description"
      className={cn('col-start-2 grid justify-items-start gap-1 text-sm [&_p]:leading-relaxed', className)}
      {...props}
    />
  );
}

export { Alert, AlertTitle, AlertDescription, alertVariants };
