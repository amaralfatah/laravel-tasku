import { Slot } from "@radix-ui/react-slot"
import { cva, type VariantProps } from "class-variance-authority"
import * as React from "react"

import { cn } from "@/lib/utils"

/**
 * One shape for every button: a 6px rectangle that lines up with the inputs
 * and selects beside it in a toolbar or a table row. The pill from DESIGN.md
 * belongs to a page with two CTAs on it, not to a dense work surface, so the
 * pill grammar lives on badges only.
 *
 * What the document does keep here: the single Action Blue accent, no shadow
 * on a control, `scale(0.95)` as the press state, and a 2px outline as focus.
 */
const buttonVariants = cva(
  "inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-sm text-sm font-normal transition-[color,background-color,border-color,transform] active:scale-[0.95] disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-4 [&_svg]:shrink-0 outline-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring aria-invalid:border-destructive",
  {
    variants: {
      variant: {
        default: "bg-primary text-primary-foreground hover:bg-primary/90",
        destructive:
          "bg-destructive text-destructive-foreground hover:bg-destructive/90 focus-visible:outline-destructive",
        /** The neutral secondary: hairline instead of fill. */
        outline: "border border-border bg-card text-foreground hover:bg-accent",
        /** The blue-hairline secondary, for the action beside a primary. */
        accent:
          "border border-primary bg-transparent text-primary hover:bg-primary/5",
        secondary: "bg-secondary text-secondary-foreground hover:bg-accent",
        /** Filled ink, for chrome actions that must outrank a plain outline. */
        utility: "bg-foreground text-background hover:bg-foreground/90",
        ghost: "hover:bg-accent hover:text-accent-foreground",
        link: "text-link underline-offset-4 hover:underline",
      },
      size: {
        default: "h-9 px-4 has-[>svg]:px-3",
        sm: "h-8 px-3 text-sm has-[>svg]:px-2.5",
        lg: "h-10 px-5 text-base has-[>svg]:px-4",
        icon: "size-9",
      },
    },
    defaultVariants: {
      variant: "default",
      size: "default",
    },
  }
)

function Button({
  className,
  variant,
  size,
  asChild = false,
  ...props
}: React.ComponentProps<"button"> &
  VariantProps<typeof buttonVariants> & {
    asChild?: boolean
  }) {
  const Comp = asChild ? Slot : "button"

  return (
    <Comp
      data-slot="button"
      className={cn(buttonVariants({ variant, size, className }))}
      {...props}
    />
  )
}

export { Button, buttonVariants }
