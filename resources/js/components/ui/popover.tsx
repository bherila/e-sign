import { Popover as PopoverPrimitive } from "@base-ui/react/popover"
import * as React from "react"

import { cn } from "@/lib/utils"

function Popover({
  ...props
}: React.ComponentProps<typeof PopoverPrimitive.Root>) {
  return <PopoverPrimitive.Root data-slot="popover" {...props} />
}

function PopoverTrigger({
  asChild,
  children,
  ...props
}: React.ComponentProps<typeof PopoverPrimitive.Trigger> & {
  asChild?: boolean
  children?: React.ReactNode
}) {
  return (
    <PopoverPrimitive.Trigger
      data-slot="popover-trigger"
      {...(asChild && React.isValidElement(children) ? { render: children } : {})}
      {...props}
    >
      {asChild && React.isValidElement(children) ? null : children}
    </PopoverPrimitive.Trigger>
  )
}

type PopoverContentProps = React.ComponentProps<typeof PopoverPrimitive.Popup> &
  Pick<
    React.ComponentProps<typeof PopoverPrimitive.Positioner>,
    "align" | "alignOffset" | "collisionPadding" | "side" | "sideOffset"
  >

function PopoverContent({
  className,
  align = "center",
  sideOffset = 4,
  alignOffset,
  collisionPadding,
  side,
  ...props
}: PopoverContentProps) {
  return (
    <PopoverPrimitive.Portal>
      <PopoverPrimitive.Positioner
        align={align}
        alignOffset={alignOffset}
        collisionPadding={collisionPadding}
        side={side}
        sideOffset={sideOffset}
        className="z-50"
      >
        <PopoverPrimitive.Popup
          data-slot="popover-content"
          className={cn(
            "bg-popover text-popover-foreground data-[open]:animate-in data-[closed]:animate-out data-[closed]:fade-out-0 data-[open]:fade-in-0 data-[closed]:zoom-out-95 data-[open]:zoom-in-95 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2 z-50 w-72 origin-[var(--transform-origin)] rounded-md border p-4 shadow-md outline-hidden",
            className
          )}
          {...props}
        />
      </PopoverPrimitive.Positioner>
    </PopoverPrimitive.Portal>
  )
}

function PopoverAnchor({ ...props }: React.ComponentProps<"div">) {
  return <div data-slot="popover-anchor" {...props} />
}

export { Popover, PopoverAnchor,PopoverContent, PopoverTrigger }
