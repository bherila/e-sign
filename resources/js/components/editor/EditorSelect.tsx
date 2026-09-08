import { useId } from "react";

import { Label } from "@/components/ui/label";
import { cn } from "@/lib/utils";

/**
 * A plain `<select>`, on purpose.
 *
 * `@/components/ui/select` is the right control for a form: it is a Base UI listbox in a
 * portal, with typeahead and a styled popup. In a table with a picker on every row it is the
 * wrong one — a portalled popup per row, and a control that has to reimplement what the
 * platform already does. The editor's whole accessibility argument is that the table is the
 * path that works without a pointer, so its controls are the ones the browser and the screen
 * reader already know, and the same component is used in the inspector so the two views
 * cannot behave differently.
 */
export interface EditorSelectOption {
  value: string;
  label: string;
}

export interface EditorSelectProps {
  label: string;
  value: string;
  options: EditorSelectOption[];
  onValueChange: (value: string) => void;
  disabled?: boolean;
  hideLabel?: boolean;
  className?: string;
  invalid?: boolean;
}

export function EditorSelect({
  label,
  value,
  options,
  onValueChange,
  disabled = false,
  hideLabel = false,
  className,
  invalid = false,
}: EditorSelectProps) {
  const id = useId();

  const select = (
    <select
      id={id}
      value={value}
      disabled={disabled}
      aria-label={hideLabel ? label : undefined}
      aria-invalid={invalid || undefined}
      onChange={(event) => onValueChange(event.target.value)}
      className={cn(
        "border-input dark:bg-input/30 h-8 w-full rounded-md border bg-transparent px-2 text-sm shadow-xs outline-none",
        "focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]",
        "aria-invalid:border-destructive aria-invalid:ring-destructive/20",
        "disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50",
        className,
      )}
    >
      {options.map((option) => (
        <option key={option.value} value={option.value}>
          {option.label}
        </option>
      ))}
    </select>
  );

  if (hideLabel) {
    return select;
  }

  return (
    <div className="flex flex-col gap-1">
      <Label htmlFor={id} className="text-xs text-muted-foreground">
        {label}
      </Label>
      {select}
    </div>
  );
}
