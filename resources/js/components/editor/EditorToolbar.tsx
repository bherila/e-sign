import {
  ChevronLeftIcon,
  ChevronRightIcon,
  PlusIcon,
  RedoIcon,
  SaveIcon,
  UndoIcon,
  ZoomInIcon,
  ZoomOutIcon,
} from "lucide-react";

import { Button } from "@/components/ui/button";
import { FIELD_TYPES, type FieldSchemaDocument, type FieldType } from "@/schema/fieldSchema";

import { EditorSelect } from "./EditorSelect";
import { NumberInput } from "./NumberInput";

/**
 * Page navigation, zoom, history, field creation, and save.
 *
 * Every control is a real button or a real form control with a name; none of them is an icon
 * on its own. Zoom is stated as a percentage and clamped to the range the editor supports, so
 * "50%" means the same thing here as in the documentation, and page navigation is a number
 * input rather than a slider because "go to page 12" is the thing people actually want.
 */
export const MIN_ZOOM = 0.5;

export const MAX_ZOOM = 2;

export const ZOOM_STEP = 0.25;

export interface EditorToolbarProps {
  document: FieldSchemaDocument;
  pageCount: number;
  currentPage: number;
  onGoToPage: (page: number) => void;
  zoom: number;
  onZoomChange: (zoom: number) => void;
  onFitWidth: () => void;
  fitWidth: boolean;
  canUndo: boolean;
  canRedo: boolean;
  onUndo: () => void;
  onRedo: () => void;
  readOnly: boolean;
  dirty: boolean;
  saving: boolean;
  onSave: () => void;
  newFieldType: FieldType;
  onNewFieldTypeChange: (type: FieldType) => void;
  newFieldRecipient: string;
  onNewFieldRecipientChange: (recipientId: string) => void;
  onAddField: () => void;
}

export function clampZoom(zoom: number): number {
  return Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, zoom));
}

export function EditorToolbar({
  document,
  pageCount,
  currentPage,
  onGoToPage,
  zoom,
  onZoomChange,
  onFitWidth,
  fitWidth,
  canUndo,
  canRedo,
  onUndo,
  onRedo,
  readOnly,
  dirty,
  saving,
  onSave,
  newFieldType,
  onNewFieldTypeChange,
  newFieldRecipient,
  onNewFieldRecipientChange,
  onAddField,
}: EditorToolbarProps) {
  return (
    // `role="group"`, not `role="toolbar"`: a toolbar promises roving-tabindex arrow-key
    // navigation between its controls, and these are ordinary tab stops with text inputs among
    // them, where arrow keys belong to the input. Claiming a pattern and not implementing it is
    // worse than not claiming it.
    <div
      role="group"
      aria-label="Field editor tools"
      className="bg-background sticky top-0 z-10 flex flex-wrap items-end gap-3 border-b py-2"
    >
      <div className="flex items-end gap-1">
        <Button
          type="button"
          variant="outline"
          size="icon-sm"
          aria-label="Previous page"
          disabled={currentPage <= 1}
          onClick={() => onGoToPage(currentPage - 1)}
        >
          <ChevronLeftIcon aria-hidden="true" />
        </Button>

        <NumberInput
          label="Page"
          value={currentPage}
          min={1}
          max={pageCount > 0 ? pageCount : undefined}
          step={1}
          className="w-20"
          onCommit={(value) => onGoToPage(Math.round(value))}
        />

        <span className="text-muted-foreground pb-2 text-xs tabular-nums">of {pageCount}</span>

        <Button
          type="button"
          variant="outline"
          size="icon-sm"
          aria-label="Next page"
          disabled={currentPage >= pageCount}
          onClick={() => onGoToPage(currentPage + 1)}
        >
          <ChevronRightIcon aria-hidden="true" />
        </Button>
      </div>

      <div className="flex items-end gap-1">
        <Button
          type="button"
          variant="outline"
          size="icon-sm"
          aria-label="Zoom out"
          disabled={zoom <= MIN_ZOOM}
          onClick={() => onZoomChange(clampZoom(zoom - ZOOM_STEP))}
        >
          <ZoomOutIcon aria-hidden="true" />
        </Button>

        <NumberInput
          label="Zoom %"
          value={Math.round(zoom * 100)}
          min={MIN_ZOOM * 100}
          max={MAX_ZOOM * 100}
          step={5}
          className="w-20"
          onCommit={(value) => onZoomChange(clampZoom(value / 100))}
        />

        <Button
          type="button"
          variant="outline"
          size="icon-sm"
          aria-label="Zoom in"
          disabled={zoom >= MAX_ZOOM}
          onClick={() => onZoomChange(clampZoom(zoom + ZOOM_STEP))}
        >
          <ZoomInIcon aria-hidden="true" />
        </Button>

        <Button
          type="button"
          variant={fitWidth ? "secondary" : "outline"}
          size="sm"
          aria-pressed={fitWidth}
          onClick={onFitWidth}
        >
          Fit width
        </Button>
      </div>

      <div className="flex items-end gap-1">
        <Button
          type="button"
          variant="outline"
          size="icon-sm"
          aria-label="Undo"
          disabled={!canUndo || readOnly}
          onClick={onUndo}
        >
          <UndoIcon aria-hidden="true" />
        </Button>
        <Button
          type="button"
          variant="outline"
          size="icon-sm"
          aria-label="Redo"
          disabled={!canRedo || readOnly}
          onClick={onRedo}
        >
          <RedoIcon aria-hidden="true" />
        </Button>
      </div>

      <div className="flex items-end gap-1">
        <EditorSelect
          label="New field type"
          value={newFieldType}
          options={FIELD_TYPES.map((type) => ({ value: type, label: type }))}
          disabled={readOnly}
          className="w-40"
          onValueChange={(value) => onNewFieldTypeChange(value as FieldType)}
        />

        <EditorSelect
          label="For recipient"
          value={newFieldRecipient}
          options={document.recipients.map((recipient) => ({
            value: recipient.id,
            label: recipient.name,
          }))}
          disabled={readOnly || document.recipients.length === 0}
          className="w-44"
          onValueChange={onNewFieldRecipientChange}
        />

        <Button
          type="button"
          size="sm"
          disabled={readOnly || document.recipients.length === 0}
          onClick={onAddField}
        >
          <PlusIcon aria-hidden="true" />
          Add field
        </Button>
      </div>

      <div className="ml-auto flex items-end gap-2">
        <span className="text-muted-foreground pb-2 text-xs" aria-live="polite">
          {readOnly ? "Read-only" : dirty ? "Unsaved changes" : "Saved"}
        </span>

        <Button type="button" size="sm" disabled={readOnly || saving || !dirty} onClick={onSave}>
          <SaveIcon aria-hidden="true" />
          {saving ? "Saving…" : "Save"}
        </Button>
      </div>
    </div>
  );
}
