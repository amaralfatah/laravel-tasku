import { Slot } from "@radix-ui/react-slot"
import { cva, type VariantProps } from "class-variance-authority"
import * as React from "react"

import { cn } from "@/lib/utils"

const buttonVariants = cva(
  "inline-flex cursor-pointer items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium transition-[color,background-color,border-color,box-shadow,transform] duration-150 ease-out active:scale-[0.98] disabled:pointer-events-none disabled:opacity-50 disabled:shadow-none [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-4 [&_svg]:shrink-0 outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive",
  {
    variants: {
      variant: {
        default:
          "bg-primary text-primary-foreground shadow-raised hover:bg-primary/90",
        destructive:
          "bg-destructive text-destructive-foreground shadow-raised hover:bg-destructive/90 focus-visible:ring-destructive/20 dark:focus-visible:ring-destructive/40",
        success:
          "bg-success text-success-foreground shadow-raised hover:bg-success/90",
        outline:
          "border border-input bg-card shadow-raised hover:border-primary/40 hover:bg-accent hover:text-accent-foreground",
        secondary:
          "bg-secondary text-secondary-foreground shadow-raised hover:bg-secondary/80",
        ghost: "hover:bg-accent hover:text-accent-foreground",
        link: "text-primary underline-offset-4 hover:underline",
      },
      // Touch devices get a 44px minimum hit area without inflating the
      // desktop density.
      size: {
        default: "h-9 px-4 py-2 has-[>svg]:px-3 pointer-coarse:h-11",
        sm: "h-8 rounded-md px-3 has-[>svg]:px-2.5 pointer-coarse:h-10",
        lg: "h-10 rounded-md px-6 has-[>svg]:px-4 pointer-coarse:h-12",
        icon: "size-9 pointer-coarse:size-11",
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
