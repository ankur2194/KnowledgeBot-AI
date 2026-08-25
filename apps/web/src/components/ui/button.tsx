import * as React from 'react';
import { cva, type VariantProps } from 'class-variance-authority';
import { Slot } from 'radix-ui';

import { cn } from '@/lib/utils';

/**
 * NO FOCUS STYLING HERE, AND THAT IS DELIBERATE. `globals.css` carries one `:focus-visible` rule —
 * a 2px `--ring` outline at 2px offset — for every focusable element in the app. shadcn ships
 * `outline-none focus-visible:ring-[3px]` instead, and both halves are wrong for us: the literal
 * `3px` is a value outside the token set, and `outline-none` would suppress the global rule that
 * replaces it. A component that re-adds either gets two indicators or none.
 *
 * THE PRESSED STATE IS MANDATORY, not a nicety. Tailwind v4 wraps `hover:` in
 * `@media (hover: hover)` on purpose, so a touch device never latches a hover state — which means
 * most widget and mobile traffic gets NO feedback at all unless `:active` is defined. The translate
 * is a whole pixel; a fractional one lands the label on a subpixel boundary and blurs it for the
 * duration of the press.
 */
const buttonVariants = cva(
  [
    'inline-flex shrink-0 items-center justify-center gap-2 rounded-lg text-base font-medium whitespace-nowrap',
    'transition-[background-color,color,box-shadow,translate] duration-(--dur-1) ease-out',
    'disabled:pointer-events-none disabled:opacity-50',
    'active:translate-y-px',
    // Kills the 300ms tap delay without suppressing the pressed state, which is the only
    // confirmation a touch user gets.
    'touch-manipulation',
    "[&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4",
  ],
  {
    variants: {
      variant: {
        // The ONE loud accent. Rule 3: if two things on a screen compete for "most saturated", one
        // of them is wrong.
        default: 'bg-primary text-primary-foreground hover:bg-primary-hover active:bg-primary-active',
        // A solid destructive fill uses --destructive-strong: the base --destructive is the icon,
        // dot, outline and text-on-card colour, and in dark mode a near-white label on it measures
        // 2.75:1. Hover lightens to the base so the press still reads.
        destructive:
          'bg-destructive-strong text-destructive-foreground hover:bg-destructive active:bg-destructive-strong',
        // The neutral action: --card with the hairline ring (P3). NEVER a border — a ring does not
        // participate in layout, so adding one on focus or error shifts no sibling.
        outline: 'bg-card shadow-hairline hover:bg-accent hover:text-accent-foreground active:bg-accent',
        // The recessed strip inside a card, used where a neutral button sits ON a card already.
        secondary: 'bg-card-inset text-foreground hover:bg-accent active:bg-accent',
        ghost: 'hover:bg-accent hover:text-accent-foreground active:bg-accent',
        link: 'text-link underline-offset-4 hover:underline active:text-link-active',
      },
      size: {
        // The control-height scale: 2rem small, 2.25rem default, 2.5rem large — and 2.75rem (44px)
        // MINIMUM on touch, which `pointer-coarse` applies without changing the desktop density.
        // The visual may stay small; the hit area may not.
        default: 'h-9 px-4 py-2 pointer-coarse:min-h-11 has-[>svg]:px-3',
        xs: "h-6 gap-1 rounded-sm px-2 text-caption pointer-coarse:min-h-11 has-[>svg]:px-1.5 [&_svg:not([class*='size-'])]:size-3",
        sm: 'h-8 gap-1.5 rounded-md px-3 pointer-coarse:min-h-11 has-[>svg]:px-2.5',
        lg: 'h-10 px-6 pointer-coarse:min-h-11 has-[>svg]:px-4',
        icon: 'size-9 pointer-coarse:size-11',
        'icon-xs': "size-6 rounded-sm pointer-coarse:size-11 [&_svg:not([class*='size-'])]:size-3",
        'icon-sm': 'size-8 rounded-md pointer-coarse:size-11',
        'icon-lg': 'size-10 pointer-coarse:size-11',
      },
    },
    defaultVariants: {
      variant: 'default',
      size: 'default',
    },
  },
);

function Button({
  className,
  variant = 'default',
  size = 'default',
  asChild = false,
  ...props
}: React.ComponentProps<'button'> &
  VariantProps<typeof buttonVariants> & {
    asChild?: boolean;
  }) {
  const Comp = asChild ? Slot.Root : 'button';

  return (
    <Comp
      data-slot="button"
      data-variant={variant}
      data-size={size}
      className={cn(buttonVariants({ variant, size, className }))}
      {...props}
    />
  );
}

export { Button, buttonVariants };
