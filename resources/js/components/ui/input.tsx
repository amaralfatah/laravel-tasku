import * as React from "react"

import { cn } from "@/lib/utils"

function Input({ className, type, ...props }: React.ComponentProps<"input">) {
  return (
    <input
      type={type}
      data-slot="input"
      className={cn(
        "border-input bg-card file:text-foreground placeholder:text-muted-foreground selection:bg-primary selection:text-primary-foreground flex h-9 w-full min-w-0 rounded-sm border px-3.5 py-1 text-base transition-[color,border-color] outline-none file:inline-flex file:h-7 file:border-0 file:bg-transparent file:text-sm file:font-medium disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm",
        // The focus signal is the documented 2px outline, not a soft ring.
        "focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-ring",
        "aria-invalid:border-destructive aria-invalid:focus-visible:outline-destructive",
        className
      )}
      {...props}
    />
  )
}

export { Input }
