import { type PointerEvent as ReactPointerEvent, useRef, useState } from "react";

import { cn } from "@/lib/utils";
import type { FieldDefinition, Rect } from "@/schema/fieldSchema";

import { NUDGE_STEP, NUDGE_STEP_LARGE } from "./editorReducer";
import type { PageTransform } from "./PageTransform";
import type { RecipientColor } from "./recipientColors";

/**
 * One field, drawn over the rendered page.
 *
 * The box is a real `<button>`: it takes focus in document order, shows the same focus ring as
 * every other control, carries an accessible name that states the recipient, the type and the
 * rectangle, and moves with the arrow keys. A `div` with a click handler would have looked the
 * same and been reachable by nobody without a mouse.
 *
 * The resize handles are deliberately **not** focusable and are `aria-hidden`. They are a
 * pointer affordance for something that already has a complete keyboard equivalent — the
 * width and height inputs in the inspector and in the table — and four extra tab stops per
 * field, in a document that may have fifty, would make the tab order unusable to buy nothing.
 * The tabular view is the accessible path, and it is a peer of this one, not a fallback.
 *
 * Drag and resize are *previewed locally* and committed once, on pointer-up. Two reasons: the
 * reducer never sees a stream of intermediate documents, so one drag is one undo step rather
 * than two hundred; and the conversion from pixels to points happens once, at the end, on the
 * real total displacement rather than accumulated per frame.
 */
export interface FieldBoxProps {
  field: FieldDefinition;
  transform: PageTransform;
  color: RecipientColor;
  recipientName: string;
  selected: boolean;
  readOnly: boolean;
  /** True when the validator has something to say about this field. Never colour alone. */
  hasIssue: boolean;
  onSelect: () => void;
  onMove: (x: number, y: number) => void;
  onResize: (rect: Rect) => void;
  onNudge: (dx: number, dy: number) => void;
  onDelete: () => void;
}

type Corner = "nw" | "ne" | "sw" | "se";

interface Gesture {
  kind: "move" | "resize";
  corner: Corner | null;
  originX: number;
  originY: number;
  dx: number;
  dy: number;
}

const CORNERS: { corner: Corner; className: string; cursor: string }[] = [
  { corner: "nw", className: "-left-1 -top-1", cursor: "nwse-resize" },
  { corner: "ne", className: "-right-1 -top-1", cursor: "nesw-resize" },
  { corner: "sw", className: "-left-1 -bottom-1", cursor: "nesw-resize" },
  { corner: "se", className: "-right-1 -bottom-1", cursor: "nwse-resize" },
];

/** The smallest rectangle a drag may produce, in points. Below this a field is unclickable. */
const MINIMUM_EXTENT = 4;

export function FieldBox({
  field,
  transform,
  color,
  recipientName,
  selected,
  readOnly,
  hasIssue,
  onSelect,
  onMove,
  onResize,
  onNudge,
  onDelete,
}: FieldBoxProps) {
  const [gesture, setGesture] = useState<Gesture | null>(null);
  const gestureRef = useRef<Gesture | null>(null);

  const preview = previewRect(field.rect, gesture, transform);
  const screen = transform.rectToScreen(preview);

  function begin(event: ReactPointerEvent<HTMLElement>, kind: "move" | "resize", corner: Corner | null): void {
    if (readOnly || event.button !== 0) {
      return;
    }

    event.preventDefault();
    event.stopPropagation();
    onSelect();

    const next: Gesture = { kind, corner, originX: event.clientX, originY: event.clientY, dx: 0, dy: 0 };
    gestureRef.current = next;
    setGesture(next);
    event.currentTarget.setPointerCapture(event.pointerId);
  }

  function track(event: ReactPointerEvent<HTMLElement>): void {
    const current = gestureRef.current;

    if (current === null) {
      return;
    }

    const next: Gesture = {
      ...current,
      dx: event.clientX - current.originX,
      dy: event.clientY - current.originY,
    };
    gestureRef.current = next;
    setGesture(next);
  }

  function finish(event: ReactPointerEvent<HTMLElement>): void {
    const current = gestureRef.current;

    if (current === null) {
      return;
    }

    if (event.currentTarget.hasPointerCapture(event.pointerId)) {
      event.currentTarget.releasePointerCapture(event.pointerId);
    }

    const committed = previewRect(field.rect, current, transform);
    gestureRef.current = null;
    setGesture(null);

    if (current.kind === "move") {
      onMove(committed.x, committed.y);
    } else {
      onResize(committed);
    }
  }

  return (
    <button
      type="button"
      data-field-id={field.id}
      data-selected={selected || undefined}
      aria-pressed={selected}
      aria-label={describeField(field, recipientName)}
      className={cn(
        "absolute flex items-center justify-center overflow-hidden rounded-[2px] border-2 text-[10px] leading-none font-medium",
        "outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50",
        selected && "ring-[3px] ring-ring/50",
        hasIssue && "border-dashed",
        readOnly ? "cursor-default" : "cursor-move",
      )}
      style={{
        left: `${screen.left}px`,
        top: `${screen.top}px`,
        width: `${screen.width}px`,
        height: `${screen.height}px`,
        borderColor: color.border,
        backgroundColor: color.fill,
        color: color.border,
      }}
      onPointerDown={(event) => begin(event, "move", null)}
      onPointerMove={track}
      onPointerUp={finish}
      onPointerCancel={finish}
      onClick={onSelect}
      onFocus={onSelect}
      onKeyDown={(event) => {
        if (readOnly) {
          return;
        }

        const step = event.shiftKey ? NUDGE_STEP_LARGE : NUDGE_STEP;

        switch (event.key) {
          case "ArrowLeft":
            event.preventDefault();
            onNudge(-step, 0);
            break;
          case "ArrowRight":
            event.preventDefault();
            onNudge(step, 0);
            break;
          case "ArrowUp":
            event.preventDefault();
            onNudge(0, -step);
            break;
          case "ArrowDown":
            event.preventDefault();
            onNudge(0, step);
            break;
          case "Delete":
          case "Backspace":
            event.preventDefault();
            onDelete();
            break;
          default:
            break;
        }
      }}
    >
      <span className="pointer-events-none truncate px-1">{field.label ?? field.type}</span>

      {selected && !readOnly
        ? CORNERS.map(({ corner, className, cursor }) => (
            <span
              key={corner}
              aria-hidden="true"
              data-handle={corner}
              className={cn("absolute size-2 rounded-[1px] border border-white", className)}
              style={{ backgroundColor: color.border, cursor }}
              onPointerDown={(event) => begin(event, "resize", corner)}
              onPointerMove={track}
              onPointerUp={finish}
              onPointerCancel={finish}
            />
          ))
        : null}
    </button>
  );
}

/**
 * The field's accessible name.
 *
 * Recipient first, because that is what colour encodes on screen and colour is never the only
 * signal; then the type, the label if there is one, the page, and the rectangle in points. A
 * screen-reader user gets from this name everything the visual box conveys, including where it
 * is, which is the whole point of stating the numbers.
 */
export function describeField(field: FieldDefinition, recipientName: string): string {
  const label = field.label === undefined ? "" : `, ${field.label}`;

  return (
    `${recipientName}: ${field.type}${label}. Page ${field.page}, ` +
    `x ${field.rect.x}, y ${field.rect.y}, width ${field.rect.width}, height ${field.rect.height} pt.` +
    (field.required ? " Required." : " Optional.") +
    (field.read_only ? " Read-only." : "")
  );
}

/** The rectangle a gesture in progress would produce, in points. Not committed until pointer-up. */
function previewRect(rect: Rect, gesture: Gesture | null, transform: PageTransform): Rect {
  if (gesture === null) {
    return rect;
  }

  const dx = transform.screenLengthToNative(gesture.dx);
  const dy = transform.screenLengthToNative(gesture.dy);

  if (gesture.kind === "move") {
    return transform.clampRect({ ...rect, x: rect.x + dx, y: rect.y + dy });
  }

  const west = gesture.corner === "nw" || gesture.corner === "sw";
  const north = gesture.corner === "nw" || gesture.corner === "ne";

  const left = west ? rect.x + dx : rect.x;
  const top = north ? rect.y + dy : rect.y;
  const width = west ? rect.width - dx : rect.width + dx;
  const height = north ? rect.height - dy : rect.height + dy;

  // A drag past the opposite edge would invert the rectangle, which the schema rejects with
  // `dimension_not_positive`. Pinning at a minimum keeps the gesture reversible instead.
  const clampedWidth = Math.max(MINIMUM_EXTENT, width);
  const clampedHeight = Math.max(MINIMUM_EXTENT, height);

  return transform.clampRect({
    x: west ? left - (clampedWidth - width) : left,
    y: north ? top - (clampedHeight - height) : top,
    width: clampedWidth,
    height: clampedHeight,
  });
}
