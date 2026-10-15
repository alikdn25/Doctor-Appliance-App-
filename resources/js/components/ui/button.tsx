import { Slot } from "@radix-ui/react-slot"
import { cva, type VariantProps } from "class-variance-authority"
import { Loader2 } from "lucide-react"
import * as React from "react"

import { cn } from "@/lib/utils"

/*
 * Four levels of volume (UX-REDESIGN §4.1); the more important the action, the more volume:
 * - default (Primary): full volume and the blue glow — one main action in view;
 * - outline / secondary (Secondary): raised white or light blue cushion, no glow;
 * - tertiary / ghost: light volume or text with an icon (Edit, Add discount);
 * - destructive: red text (Delete, Void); destructiveSolid: filled red, only in a confirmation dialog.
 * 48 px high on a phone, 40 on a desktop; radius 14; pressing sinks it by 1 px.
 */
const buttonVariants = cva(
  "da-press inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-[14px] text-base font-semibold md:text-sm disabled:pointer-events-none disabled:opacity-60 [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-5 md:[&_svg:not([class*='size-'])]:size-4 [&_svg]:shrink-0 outline-none focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive",
  {
    variants: {
      variant: {
        default: "da-primary",
        outline: "da-raised",
        secondary: "da-soft",
        tertiary: "da-tertiary",
        ghost: "text-link hover:bg-white/60",
        destructive: "da-destructive-text hover:bg-danger/5 focus-visible:ring-destructive/20",
        destructiveSolid: "da-danger focus-visible:ring-destructive/20",
        link: "text-link underline-offset-4 hover:underline",
      },
      size: {
        default: "h-12 px-4 has-[>svg]:px-3.5 md:h-10",
        sm: "h-10 rounded-xl px-3 text-sm has-[>svg]:px-2.5 md:h-9",
        lg: "h-14 px-6 text-base has-[>svg]:px-5",
        icon: "size-11",
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
  loading = false,
  disabled,
  children,
  ...props
}: React.ComponentProps<"button"> &
  VariantProps<typeof buttonVariants> & {
    asChild?: boolean
    /** Shows a spinner and blocks a second tap while the action runs. */
    loading?: boolean
  }) {
  const Comp = asChild ? Slot : "button"

  return (
    <Comp
      data-slot="button"
      data-variant={variant ?? "default"}
      className={cn(buttonVariants({ variant, size, className }))}
      disabled={asChild ? undefined : disabled || loading}
      aria-busy={loading || undefined}
      {...props}
    >
      {loading && !asChild ? (
        <>
          <Loader2 className="animate-spin" aria-hidden="true" />
          {children}
        </>
      ) : (
        children
      )}
    </Comp>
  )
}

export { Button, buttonVariants }
