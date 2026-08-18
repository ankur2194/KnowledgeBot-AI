import * as React from "react"

import { cn } from "@/lib/utils"

function Textarea({ className, ...props }: React.ComponentProps<"textarea">) {
  return (
    <textarea
      data-slot="textarea"
      className={cn(
        // Same anatomy as <Input>: --card-inset fill, --input RING (not a border — a ring does not
        // participate in layout, so swapping it on error shifts no sibling).
        "flex field-sizing-content min-h-16 w-full rounded-md bg-card-inset px-3 py-2 text-base ring-1 ring-input transition-[color,box-shadow] duration-(--dur-1) ease-out placeholder:text-muted-foreground disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:ring-destructive",
        className
      )}
      {...props}
    />
  )
}

export { Textarea }
