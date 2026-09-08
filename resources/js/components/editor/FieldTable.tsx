import { Trash2Icon } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import {
  Table,
  TableBody,
  TableCaption,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { cn } from "@/lib/utils";
import { FIELD_TYPES, type FieldSchemaDocument } from "@/schema/fieldSchema";

import type { FieldPatch } from "./editorReducer";
import { EditorSelect } from "./EditorSelect";
import { NumberInput } from "./NumberInput";

/**
 * The tabular alternative to dragging.
 *
 * HANDOFF §7 asks for it and this is not a consolation prize: every property of every field is
 * editable here, in native units, with platform controls in document order. Placing a signature
 * box with the keyboard alone means tabbing to a row and typing four numbers, and that path is
 * complete — nothing in the editor can only be done by dragging.
 *
 * It is also the view that scales. Fifty fields spread over forty pages are one scrollable
 * list here and forty page loads on the canvas, so this is frequently the faster way to work
 * even with a pointer.
 *
 * Every cell control carries its own accessible name (`aria-label`), because a column header
 * alone does not name a control for a screen reader reading cell by cell, and the name has to
 * say *which* field it belongs to.
 */
export interface FieldTableProps {
  document: FieldSchemaDocument;
  /** Pages the document actually has, so the page cell cannot offer one that does not exist. */
  pageCount: number;
  selectedFieldId: string | null;
  readOnly: boolean;
  /** Ids the validator has something to say about. Marked in text, not only by styling. */
  issueFieldIds: Set<string>;
  onSelect: (fieldId: string) => void;
  onPatch: (fieldId: string, patch: FieldPatch) => void;
  onDuplicate: (fieldId: string) => void;
  onDelete: (fieldId: string) => void;
}

export function FieldTable({
  document,
  pageCount,
  selectedFieldId,
  readOnly,
  issueFieldIds,
  onSelect,
  onPatch,
  onDuplicate,
  onDelete,
}: FieldTableProps) {
  const recipientOptions = document.recipients.map((recipient) => ({
    value: recipient.id,
    label: `${recipient.name} (${recipient.id})`,
  }));

  const typeOptions = FIELD_TYPES.map((type) => ({ value: type, label: type }));

  return (
    <div className="w-full overflow-x-auto">
      <Table className="min-w-[1180px]">
        <TableCaption>
          Every field in this version, editable in native units (pt, top-left origin, 1-based
          pages). This view is a peer of the page view: nothing here needs a pointer.
        </TableCaption>

        <TableHeader>
          <TableRow>
            <TableHead scope="col">Field</TableHead>
            <TableHead scope="col">Recipient</TableHead>
            <TableHead scope="col">Type</TableHead>
            <TableHead scope="col">Page</TableHead>
            <TableHead scope="col">x</TableHead>
            <TableHead scope="col">y</TableHead>
            <TableHead scope="col">Width</TableHead>
            <TableHead scope="col">Height</TableHead>
            <TableHead scope="col">Required</TableHead>
            <TableHead scope="col">Read-only</TableHead>
            <TableHead scope="col">Label</TableHead>
            <TableHead scope="col">Prefill variable</TableHead>
            <TableHead scope="col">Actions</TableHead>
          </TableRow>
        </TableHeader>

        <TableBody>
          {document.fields.map((field) => {
            const flagged = issueFieldIds.has(field.id);

            return (
              <TableRow
                key={field.id}
                data-field-id={field.id}
                data-selected={field.id === selectedFieldId || undefined}
                className={cn(field.id === selectedFieldId && "bg-muted/60")}
                onFocus={() => onSelect(field.id)}
              >
                <TableCell className="font-mono text-xs">
                  {field.id}
                  {flagged ? (
                    <span className="text-destructive block text-[10px]">has validation errors</span>
                  ) : null}
                </TableCell>

                <TableCell>
                  <EditorSelect
                    hideLabel
                    label={`Recipient for ${field.id}`}
                    value={field.recipient_id}
                    options={recipientOptions}
                    disabled={readOnly}
                    onValueChange={(value) => onPatch(field.id, { recipient_id: value })}
                    className="min-w-[10rem]"
                  />
                </TableCell>

                <TableCell>
                  <EditorSelect
                    hideLabel
                    label={`Type of ${field.id}`}
                    value={field.type}
                    options={typeOptions}
                    disabled={readOnly}
                    onValueChange={(value) =>
                      onPatch(field.id, { type: value as (typeof FIELD_TYPES)[number] })
                    }
                    className="min-w-[9rem]"
                  />
                </TableCell>

                <TableCell>
                  <NumberInput
                    hideLabel
                    label={`Page of ${field.id}`}
                    value={field.page}
                    min={1}
                    max={pageCount > 0 ? pageCount : undefined}
                    step={1}
                    disabled={readOnly}
                    className="w-20"
                    onCommit={(value) => onPatch(field.id, { page: Math.round(value) })}
                  />
                </TableCell>

                <TableCell>
                  <NumberInput
                    hideLabel
                    label={`x of ${field.id}`}
                    unit="pt"
                    value={field.rect.x}
                    disabled={readOnly}
                    className="w-24"
                    onCommit={(value) => onPatch(field.id, { rect: { x: value } })}
                  />
                </TableCell>

                <TableCell>
                  <NumberInput
                    hideLabel
                    label={`y of ${field.id}`}
                    unit="pt"
                    value={field.rect.y}
                    disabled={readOnly}
                    className="w-24"
                    onCommit={(value) => onPatch(field.id, { rect: { y: value } })}
                  />
                </TableCell>

                <TableCell>
                  <NumberInput
                    hideLabel
                    label={`Width of ${field.id}`}
                    unit="pt"
                    value={field.rect.width}
                    disabled={readOnly}
                    className="w-24"
                    onCommit={(value) => onPatch(field.id, { rect: { width: value } })}
                  />
                </TableCell>

                <TableCell>
                  <NumberInput
                    hideLabel
                    label={`Height of ${field.id}`}
                    unit="pt"
                    value={field.rect.height}
                    disabled={readOnly}
                    className="w-24"
                    onCommit={(value) => onPatch(field.id, { rect: { height: value } })}
                  />
                </TableCell>

                <TableCell>
                  <Checkbox
                    aria-label={`${field.id} is required`}
                    checked={field.required}
                    disabled={readOnly}
                    onCheckedChange={(checked) => onPatch(field.id, { required: checked === true })}
                  />
                </TableCell>

                <TableCell>
                  <Checkbox
                    aria-label={`${field.id} is read-only`}
                    checked={field.read_only}
                    disabled={readOnly}
                    onCheckedChange={(checked) => onPatch(field.id, { read_only: checked === true })}
                  />
                </TableCell>

                <TableCell>
                  <Input
                    aria-label={`Label of ${field.id}`}
                    value={field.label ?? ""}
                    disabled={readOnly}
                    className="h-8 w-40"
                    onChange={(event) => onPatch(field.id, { label: event.target.value })}
                  />
                </TableCell>

                <TableCell>
                  <Input
                    aria-label={`Prefill variable of ${field.id}`}
                    value={field.prefill?.variable ?? ""}
                    disabled={readOnly}
                    placeholder="recipient.name"
                    className="h-8 w-44 font-mono text-xs"
                    onChange={(event) =>
                      onPatch(field.id, { prefillVariable: event.target.value })
                    }
                  />
                </TableCell>

                <TableCell>
                  <div className="flex items-center gap-1">
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      disabled={readOnly}
                      onClick={() => onDuplicate(field.id)}
                    >
                      Duplicate
                    </Button>
                    <Button
                      type="button"
                      variant="ghost"
                      size="icon-sm"
                      aria-label={`Delete ${field.id}`}
                      disabled={readOnly}
                      onClick={() => onDelete(field.id)}
                    >
                      <Trash2Icon aria-hidden="true" />
                    </Button>
                  </div>
                </TableCell>
              </TableRow>
            );
          })}

          {document.fields.length === 0 ? (
            <TableRow>
              <TableCell colSpan={13} className="text-muted-foreground text-sm">
                No fields yet. An empty field set is a valid draft; requiring at least one field
                is a send-time gate, not a schema rule.
              </TableCell>
            </TableRow>
          ) : null}
        </TableBody>
      </Table>
    </div>
  );
}
