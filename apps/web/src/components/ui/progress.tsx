"use client"

import * as React from "react"
import { Progress as ProgressPrimitive } from "radix-ui"

import { cn } from "@/lib/utils"

/**
 * ── `value` IS FORWARDED TO THE ROOT, AND IT WAS NOT ─────────────────────────────────────────────
 *
 * The registry source destructures `value` out of `props` to compute the indicator's transform and
 * then spreads only the REMAINDER onto `ProgressPrimitive.Root` — so Radix never received it. The
 * bar filled correctly and the element rendered `data-state="indeterminate"` with NO
 * `aria-valuenow`, permanently, for every value. That is the whole accessible payload of the
 * primitive: a screen reader was told "a progress bar, currently indeterminate" while a sighted
 * user watched it climb to 90%.
 *
 * It is invisible in review because the visual is driven by the destructured local and looks right,
 * and axe does not flag a progressbar with no `aria-valuenow` — an indeterminate one is legitimate.
 * Caught by `tests/components/upload-dropzone.test.tsx`, which asserts the attribute per row rather
 * than asserting the bar exists.
 *
 * `value` is still read below for the transform, because Radix's Indicator does not set a width or
 * a transform of its own — the registry's approach there is correct, and only the missing forward
 * was the bug.
 */
function Progress({
  className,
  value,
  ...props
}: React.ComponentProps<typeof ProgressPrimitive.Root>) {
  return (
    <ProgressPrimitive.Root
      data-slot="progress"
      value={value}
      className={cn(
        // P13: a 6px --card-inset track with a --primary fill. The label sits ABOVE the bar with the
        // count, never inside it — and an indeterminate job gets an indeterminate indicator rather
        // than a determinate bar parked at 90%.
        "relative h-1.5 w-full overflow-hidden rounded-full bg-card-inset",
        className
      )}
      {...props}
    >
      <ProgressPrimitive.Indicator
        data-slot="progress-indicator"
        className="h-full w-full flex-1 bg-primary transition-all"
        style={{ transform: `translateX(-${100 - (value || 0)}%)` }}
      />
    </ProgressPrimitive.Root>
  )
}

export { Progress }
