'use client';

import * as React from 'react';
import { CheckIcon } from 'lucide-react';
import { Checkbox as CheckboxPrimitive } from 'radix-ui';

import { cn } from '@/lib/utils';

/**
 * `--radius-xs` is the checkbox step — and it is `max(0px, calc(var(--radius) - 6px))`, not a
 * literal. A tenant may set `--radius: 0rem`, at which point an unwrapped `calc()` goes negative,
 * the whole `border-radius` declaration becomes invalid, and the browser drops it rather than
 * clamping. Nothing warns.
 *
 * Focus is the global `:focus-visible` outline; no `outline-none` and no local ring.
 */
function Checkbox({ className, ...props }: React.ComponentProps<typeof CheckboxPrimitive.Root>) {
  return (
    <CheckboxPrimitive.Root
      data-slot="checkbox"
      className={cn(
        'peer size-4 shrink-0 rounded-xs bg-card-inset ring-1 ring-input',
        'transition-[background-color,box-shadow] duration-(--dur-1) ease-out',
        'disabled:cursor-not-allowed disabled:opacity-50',
        'aria-invalid:ring-destructive',
        'data-[state=checked]:bg-primary data-[state=checked]:text-primary-foreground data-[state=checked]:ring-primary',
        className,
      )}
      {...props}
    >
      <CheckboxPrimitive.Indicator
        data-slot="checkbox-indicator"
        className="grid place-content-center text-current"
      >
        <CheckIcon className="size-3.5" />
      </CheckboxPrimitive.Indicator>
    </CheckboxPrimitive.Root>
  );
}

export { Checkbox };
