import * as React from 'react';

import { cn } from '@/lib/utils';

/**
 * The form control (kb-ui-patterns P10): 2.25rem tall, `--card-inset` fill, `--input` outline,
 * `--radius-md`.
 *
 * THE OUTLINE IS A RING, NOT A BORDER (kb-motion-and-effects E2). A ring is a box-shadow and does
 * not participate in layout, so swapping it on focus or on error shifts nothing; a border added at
 * the same moments moves every sibling by a pixel.
 *
 * WHY THE FILL MATTERS MORE THAN THE OUTLINE: WCAG 1.4.11 wants 3:1 for whatever visually
 * identifies a control's boundary, and `--input` does not clear that. In this design language a
 * field is identified by its `--card-inset` fill against the `--card` it sits on — which is why the
 * outline is near-invisible and the field is still obvious. The rule that falls out of it: a
 * control whose fill matches its container must use `--border-strong` instead. The search field in
 * a header that is already `--card-inset` is the case that catches people.
 *
 * The placeholder is `--muted-foreground`, not `--subtle-foreground`. A placeholder is text, and
 * `--subtle-foreground` does not clear 4.5:1 and never will.
 *
 * Focus comes from the global `:focus-visible` rule in globals.css — no `outline-none` here.
 */
function Input({ className, type, ...props }: React.ComponentProps<'input'>) {
  return (
    <input
      type={type}
      data-slot="input"
      className={cn(
        'h-9 w-full min-w-0 rounded-md bg-card-inset px-3 py-1 text-base ring-1 ring-input',
        'transition-[color,box-shadow] duration-(--dur-1) ease-out',
        'pointer-coarse:min-h-11',
        'selection:bg-primary selection:text-primary-foreground',
        'file:inline-flex file:h-7 file:border-0 file:bg-transparent file:text-sm file:font-medium file:text-foreground',
        'placeholder:text-muted-foreground',
        'disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50',
        // Colour alone is not an error state — the message and its glyph are rendered by the form
        // field (P10). This is the half of it that lives on the control.
        'aria-invalid:ring-destructive',
        className,
      )}
      {...props}
    />
  );
}

export { Input };
