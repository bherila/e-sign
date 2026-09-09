import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  DEFAULT_ANCHOR_PLACEMENT,
  FIELD_TYPES,
  type FieldDefinition,
  type FieldSchemaDocument,
} from "@/schema/fieldSchema";

import type { FieldPatch } from "./editorReducer";
import { EditorSelect } from "./EditorSelect";
import { NumberInput } from "./NumberInput";

/**
 * Everything about the selected field, in native units.
 *
 * The same controls the table row carries, in a shape that suits one field at a time. They are
 * literally the same components, so a value typed here and a value typed there are parsed,
 * rounded and committed by one piece of code.
 *
 * The prefill input validates against the variable list the server supplied. When that list is
 * empty the input still accepts a name and the editor says the check was skipped, because a
 * template genuinely does not know its sending context — reporting "no such variable" against
 * an empty list would be reporting a check that was never run.
 */
export interface FieldInspectorProps {
  document: FieldSchemaDocument;
  field: FieldDefinition | null;
  pageCount: number;
  readOnly: boolean;
  /** Prefill variables the sending context can resolve. Empty means "unknown here". */
  variables: string[];
  onPatch: (fieldId: string, patch: FieldPatch) => void;
  onDuplicate: (fieldId: string) => void;
  onDelete: (fieldId: string) => void;
}

export function FieldInspector({
  document,
  field,
  pageCount,
  readOnly,
  variables,
  onPatch,
  onDuplicate,
  onDelete,
}: FieldInspectorProps) {
  if (field === null) {
    return (
      <section aria-labelledby="editor-inspector" className="flex flex-col gap-2">
        <h2 id="editor-inspector" className="text-sm font-semibold">
          Field
        </h2>
        <p className="text-muted-foreground text-sm">
          No field selected. Choose one on the page, or work in the fields table, where every
          property is editable without a pointer.
        </p>
      </section>
    );
  }

  const prefill = field.prefill?.variable ?? "";
  const unknownVariable = variables.length > 0 && prefill !== "" && !variables.includes(prefill);

  return (
    <section aria-labelledby="editor-inspector" className="flex flex-col gap-3">
      <h2 id="editor-inspector" className="text-sm font-semibold">
        Field <span className="font-mono text-xs">{field.id}</span>
      </h2>

      <EditorSelect
        label="Recipient"
        value={field.recipient_id}
        options={document.recipients.map((recipient) => ({
          value: recipient.id,
          label: `${recipient.name} (${recipient.id})`,
        }))}
        disabled={readOnly}
        onValueChange={(value) => onPatch(field.id, { recipient_id: value })}
      />

      <EditorSelect
        label="Type"
        value={field.type}
        options={FIELD_TYPES.map((type) => ({ value: type, label: type }))}
        disabled={readOnly}
        onValueChange={(value) => onPatch(field.id, { type: value as (typeof FIELD_TYPES)[number] })}
      />

      <div className="grid grid-cols-2 gap-2">
        <NumberInput
          label="Page"
          value={field.page}
          min={1}
          max={pageCount > 0 ? pageCount : undefined}
          step={1}
          disabled={readOnly}
          onCommit={(value) => onPatch(field.id, { page: Math.round(value) })}
        />
        <div />
        <NumberInput
          label="x"
          unit="pt"
          value={field.rect.x}
          disabled={readOnly}
          onCommit={(value) => onPatch(field.id, { rect: { x: value } })}
        />
        <NumberInput
          label="y"
          unit="pt"
          value={field.rect.y}
          disabled={readOnly}
          onCommit={(value) => onPatch(field.id, { rect: { y: value } })}
        />
        <NumberInput
          label="Width"
          unit="pt"
          value={field.rect.width}
          disabled={readOnly}
          onCommit={(value) => onPatch(field.id, { rect: { width: value } })}
        />
        <NumberInput
          label="Height"
          unit="pt"
          value={field.rect.height}
          disabled={readOnly}
          onCommit={(value) => onPatch(field.id, { rect: { height: value } })}
        />
      </div>

      <div className="flex flex-col gap-2">
        <Label className="text-sm">
          <Checkbox
            checked={field.required}
            disabled={readOnly}
            onCheckedChange={(checked) => onPatch(field.id, { required: checked === true })}
          />
          Required
        </Label>

        <Label className="text-sm">
          <Checkbox
            checked={field.read_only}
            disabled={readOnly}
            onCheckedChange={(checked) => onPatch(field.id, { read_only: checked === true })}
          />
          Read-only for the signer
        </Label>
      </div>

      <div className="flex flex-col gap-1">
        <Label htmlFor="editor-field-label" className="text-xs text-muted-foreground">
          Label
        </Label>
        <Input
          id="editor-field-label"
          value={field.label ?? ""}
          disabled={readOnly}
          className="h-8"
          onChange={(event) => onPatch(field.id, { label: event.target.value })}
        />
      </div>

      <div className="flex flex-col gap-1">
        <Label htmlFor="editor-field-alias" className="text-xs text-muted-foreground">
          Template alias
        </Label>
        <Input
          id="editor-field-alias"
          value={field.alias ?? ""}
          disabled={readOnly}
          className="h-8 font-mono text-xs"
          onChange={(event) => onPatch(field.id, { alias: event.target.value })}
        />
        <p className="text-muted-foreground text-xs">
          A stable handle integrations address this field by. Unique within the document, and
          never rewritten on import.
        </p>
      </div>

      <div className="flex flex-col gap-1">
        <Label htmlFor="editor-field-prefill" className="text-xs text-muted-foreground">
          Prefill variable
        </Label>
        <Input
          id="editor-field-prefill"
          value={prefill}
          disabled={readOnly}
          placeholder="recipient.name"
          list={variables.length > 0 ? "editor-prefill-variables" : undefined}
          aria-invalid={unknownVariable || undefined}
          aria-describedby="editor-field-prefill-help"
          className="h-8 font-mono text-xs"
          onChange={(event) => onPatch(field.id, { prefillVariable: event.target.value })}
        />

        {variables.length > 0 ? (
          <datalist id="editor-prefill-variables">
            {variables.map((variable) => (
              <option key={variable} value={variable} />
            ))}
          </datalist>
        ) : null}

        <p id="editor-field-prefill-help" className="text-muted-foreground text-xs">
          {variables.length === 0
            ? "No variable list is configured for this deployment, so resolvability is not checked here. The send-time gate still checks it."
            : unknownVariable
              ? `"${prefill}" is not one of the ${variables.length} variables the sending context can resolve.`
              : `One of ${variables.length} variables the sending context can resolve.`}
        </p>
      </div>

      {field.anchor === undefined ? null : (
        <div className="rounded-md border p-2 text-xs">
          <p className="font-medium">Anchor placement</p>
          <p className="text-muted-foreground">
            {/*
              The two modes do opposite things to this field's rectangle, so they cannot share a
              sentence. Telling somebody who positioned a field by hand that it will be moved is
              the more damaging half of the confusion, which is why `cross_check` says plainly
              that the rectangle stays where they put it.
            */}
            {(field.anchor.placement ?? DEFAULT_ANCHOR_PLACEMENT) === "cross_check" ? (
              <>
                Checked against <span className="font-mono">{JSON.stringify(field.anchor.text)}</span>,
                occurrence <span className="font-mono">{String(field.anchor.occurrence)}</span>. This
                rectangle decides where the field goes and is left exactly as you set it; the anchor
                only has to agree with it, and a disagreement larger than the tolerance stops the
                send rather than moving anything.
              </>
            ) : (
              <>
                Placed relative to <span className="font-mono">{JSON.stringify(field.anchor.text)}</span>,
                occurrence <span className="font-mono">{String(field.anchor.occurrence)}</span>. The
                resolver writes the resolved rectangle before send, so this rectangle is a
                placeholder for its position and supplies only its size.
              </>
            )}{" "}
            Editing the rectangle here does not remove the anchor.
          </p>
        </div>
      )}

      <div className="flex items-center gap-2">
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
          variant="destructive"
          size="sm"
          disabled={readOnly}
          onClick={() => onDelete(field.id)}
        >
          Delete
        </Button>
      </div>
    </section>
  );
}
