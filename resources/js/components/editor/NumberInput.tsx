import { useEffect, useId, useState } from "react";

import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { cn } from "@/lib/utils";
import { roundCoordinate } from "@/schema/fieldSchema";

/**
 * A numeric input in native units, used by both the inspector and the table.
 *
 * One component for both, because the tabular view is not a lesser copy of the inspector: it
 * is the path that has to work when dragging is not available, so the two must accept exactly
 * the same values, round them the same way, and report the same thing to a screen reader.
 *
 * Values commit as they are typed rather than on blur. A field's rectangle is the thing being
 * edited, and a validation panel that only tells you about the number you typed once you have
 * moved away from it is a validation panel that arrives too late to be useful.
 *
 * The local text state exists so that a half-typed value ("-", "12.") stays on screen: a
 * controlled input formatted from the committed number would erase the minus sign as soon as
 * it was typed.
 */
export interface NumberInputProps {
  /** Accessible name. Rendered as a visible `<label>` unless `hideLabel` is set. */
  label: string;
  value: number;
  onCommit: (value: number) => void;
  /** Native step, so the browser's own up/down keys move by a useful amount. */
  step?: number | undefined;
  min?: number | undefined;
  max?: number | undefined;
  disabled?: boolean;
  /** For a dense table, where the column header is the visible label. */
  hideLabel?: boolean;
  /** Appended to the accessible name: "x (pt)". Never parsed back out of the value. */
  unit?: string | undefined;
  className?: string | undefined;
  invalid?: boolean;
}

export function formatNumber(value: number): string {
  return Number.isFinite(value) ? String(roundCoordinate(value)) : "";
}

export function NumberInput({
  label,
  value,
  onCommit,
  step = 1,
  min,
  max,
  disabled = false,
  hideLabel = false,
  unit,
  className,
  invalid = false,
}: NumberInputProps) {
  const id = useId();
  const [text, setText] = useState(() => formatNumber(value));
  const [editing, setEditing] = useState(false);

  useEffect(() => {
    if (!editing) {
      setText(formatNumber(value));
    }
  }, [value, editing]);

  const accessibleName = unit === undefined ? label : `${label} (${unit})`;

  const input = (
    <Input
      id={id}
      type="number"
      inputMode="decimal"
      step={step}
      {...(min === undefined ? {} : { min })}
      {...(max === undefined ? {} : { max })}
      value={text}
      disabled={disabled}
      aria-label={hideLabel ? accessibleName : undefined}
      aria-invalid={invalid || undefined}
      className={cn("h-8 tabular-nums", className)}
      onFocus={() => setEditing(true)}
      onBlur={() => {
        setEditing(false);
        setText(formatNumber(value));
      }}
      onChange={(event) => {
        const next = event.target.value;
        setText(next);

        if (next.trim() === "") {
          return;
        }

        const parsed = Number(next);

        if (Number.isFinite(parsed)) {
          onCommit(parsed);
        }
      }}
    />
  );

  if (hideLabel) {
    return input;
  }

  return (
    <div className="flex flex-col gap-1">
      <Label htmlFor={id} className="text-xs text-muted-foreground">
        {accessibleName}
      </Label>
      {input}
    </div>
  );
}
