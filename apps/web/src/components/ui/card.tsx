import * as React from 'react';

import { cn } from '@/lib/utils';

/**
 * The standard content card (kb-ui-patterns P5): `--card` on `--canvas`, `--radius-2xl`,
 * `--shadow-md`, `--card-pad-md`.
 *
 * NO BORDER. Elevation is shadow and radius; the hairline `--border` is for structure INSIDE a card
 * — table rules, dividers, input outlines — never to draw the card itself. A card carrying both is
 * a bug that only shows up in dark mode, where the ladder already contains an inset light ring and
 * the extra border reads as a double edge.
 *
 * It also has to sit on `--canvas` to look like anything: the ladder is tuned for a card/canvas
 * lightness gap of 0.055 OKLCH, so the same shadow on a `--card` parent is invisible. Nest with
 * `--card-inset`, never with another `--card`.
 */
function Card({ className, ...props }: React.ComponentProps<'div'>) {
  return (
    <div
      data-slot="card"
      className={cn(
        'flex flex-col gap-4 rounded-2xl bg-card py-card-pad-md text-foreground shadow-md',
        className,
      )}
      {...props}
    />
  );
}

function CardHeader({ className, ...props }: React.ComponentProps<'div'>) {
  return (
    <div
      data-slot="card-header"
      className={cn(
        '@container/card-header grid auto-rows-min grid-rows-[auto_auto] items-start gap-1 px-card-pad-md has-data-[slot=card-action]:grid-cols-[1fr_auto] [.border-b]:pb-card-pad-md',
        className,
      )}
      {...props}
    />
  );
}

/**
 * A REAL HEADING, not a styled div. Screen-reader navigation is by heading, and a page of divs is a
 * page with no structure (kb-ui-accessibility). `h3` is the default because a card sits under a
 * page `h1` and an optional section `h2`; pass `as` where the outline genuinely differs.
 */
function CardTitle({
  className,
  as: Comp = 'h3',
  ...props
}: React.ComponentProps<'h3'> & { as?: 'h2' | 'h3' | 'h4' }) {
  return <Comp data-slot="card-title" className={cn('text-h3', className)} {...props} />;
}

function CardDescription({ className, ...props }: React.ComponentProps<'div'>) {
  return (
    <div
      data-slot="card-description"
      className={cn('text-sm text-muted-foreground', className)}
      {...props}
    />
  );
}

function CardAction({ className, ...props }: React.ComponentProps<'div'>) {
  return (
    <div
      data-slot="card-action"
      className={cn('col-start-2 row-span-2 row-start-1 self-start justify-self-end', className)}
      {...props}
    />
  );
}

function CardContent({ className, ...props }: React.ComponentProps<'div'>) {
  return <div data-slot="card-content" className={cn('px-card-pad-md', className)} {...props} />;
}

function CardFooter({ className, ...props }: React.ComponentProps<'div'>) {
  return (
    <div
      data-slot="card-footer"
      className={cn('flex items-center px-card-pad-md [.border-t]:pt-card-pad-md', className)}
      {...props}
    />
  );
}

/**
 * The one divider a card is allowed (P5): `--border`, bleeding to the card edge. A second divider
 * means the card has two jobs, which means it is two cards.
 */
function CardRule({ className, ...props }: React.ComponentProps<'hr'>) {
  return (
    <hr
      data-slot="card-rule"
      className={cn('-mx-card-pad-md border-t border-border', className)}
      {...props}
    />
  );
}

export {
  Card,
  CardHeader,
  CardFooter,
  CardTitle,
  CardAction,
  CardDescription,
  CardContent,
  CardRule,
};
