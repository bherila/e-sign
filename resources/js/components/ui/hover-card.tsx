import { PreviewCard as HoverCardPrimitive } from "@base-ui/react/preview-card"
import * as React from "react"

import { cn } from "@/lib/utils"

function HoverCard({
  ...props
}: React.ComponentProps<typeof HoverCardPrimitive.Root>) {
  return <HoverCardPrimitive.Root data-slot="hover-card" {...props} />
}

function HoverCardTrigger({
  asChild,
  children,
  ...props
}: React.ComponentProps<typeof HoverCardPrimitive.Trigger> & {
  asChild?: boolean
  children?: React.ReactNode
}) {
  return (
    <HoverCardPrimitive.Trigger
      data-slot="hover-card-trigger"
      {...(asChild && React.isValidElement(children) ? { render: children } : {})}
      {...props}
    >
      {asChild && React.isValidElement(children) ? null : children}
    </HoverCardPrimitive.Trigger>
  )
}

type HoverCardContentProps =
  React.ComponentProps<typeof HoverCardPrimitive.Popup> &
    Pick<
      React.ComponentProps<typeof HoverCardPrimitive.Positioner>,
      "align" | "alignOffset" | "collisionPadding" | "side" | "sideOffset"
    >

function HoverCardContent({
  className,
  align = "center",
  alignOffset,
  collisionPadding,
  side,
  sideOffset = 4,
  ...props
}: HoverCardContentProps) {
  return (
    <HoverCardPrimitive.Portal data-slot="hover-card-portal">
      <HoverCardPrimitive.Positioner
        align={align}
        alignOffset={alignOffset}
        collisionPadding={collisionPadding}
        side={side}
        sideOffset={sideOffset}
        className="z-50"
      >
        <HoverCardPrimitive.Popup
          data-slot="hover-card-content"
          className={cn(
            "bg-popover text-popover-foreground data-[open]:animate-in data-[closed]:animate-out data-[closed]:fade-out-0 data-[open]:fade-in-0 data-[closed]:zoom-out-95 data-[open]:zoom-in-95 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2 w-64 origin-[var(--transform-origin)] rounded-md border p-4 shadow-md outline-hidden",
            className
          )}
          {...props}
        />
      </HoverCardPrimitive.Positioner>
    </HoverCardPrimitive.Portal>
  )
}

export { HoverCard, HoverCardContent, HoverCardTrigger }
