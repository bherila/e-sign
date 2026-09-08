import type { FieldDefinition } from "@/schema/fieldSchema";

import { SignatureCapture } from "./SignatureCapture";
import type { FieldValue } from "./types";

/**
 * One control for one field the signer owns.
 *
 * The control is chosen from the field's declared *type*, never from its label or its
 * contents, so a field's kind means the same thing here, in the PHP validator, and in the
 * sealed PDF. There is deliberately no free-text fallback: an unhandled type is reported as
 * one rather than rendered as a text box, because a text box for a checkbox would collect a
 * value the server is about to refuse.
 *
 * `signing_date` never reaches here. It is supplied by the service from the recipient's own
 * attestation (`FieldMateriality::isServiceSupplied()`), and the payload lists it separately
 * so the review pane can show "captured when you sign" instead of an input nobody may fill.
 */
export interface FieldEntryProps {
  field: FieldDefinition;
  value: FieldValue;
  recipientName: string;
  maxSignatureBytes: number;
  onChange: (value: FieldValue) => void;
}

export function FieldEntry({
  field,
  value,
  recipientName,
  maxSignatureBytes,
  onChange,
}: FieldEntryProps) {
  const label = field.label ?? defaultLabel(field);
  const inputId = `field-${field.id}`;

  if (field.type === "signature" || field.type === "initials") {
    return (
      <SignatureCapture
        fieldId={field.id}
        label={label}
        kind={field.type}
        suggestedName={recipientName}
        value={typeof value === "string" ? value : null}
        maxBytes={maxSignatureBytes}
        onAdopt={(dataUrl) => onChange(dataUrl)}
        onClear={() => onChange(null)}
      />
    );
  }

  if (field.type === "checkbox") {
    return (
      <div className="flex items-start gap-3">
        <input
          id={inputId}
          type="checkbox"
          checked={value === true}
          onChange={(event) => onChange(event.target.checked)}
          className="mt-1 h-6 w-6 shrink-0"
        />
        <label htmlFor={inputId} className="text-sm leading-6">
          {label}
          {field.required ? <RequiredMark /> : null}
        </label>
      </div>
    );
  }

  if (field.type === "agreement_date") {
    return (
      <label htmlFor={inputId} className="block">
        <span className="text-sm font-medium">
          {label}
          {field.required ? <RequiredMark /> : null}
        </span>
        <input
          id={inputId}
          type="date"
          value={typeof value === "string" ? value : ""}
          onChange={(event) => onChange(event.target.value === "" ? null : event.target.value)}
          className="mt-1 block w-full min-h-11 rounded-md border border-black/20 bg-transparent px-3 text-base dark:border-white/20"
        />
        <span className="text-muted-foreground mt-1 block text-xs">
          The agreement's own effective date. It is the same for everybody signing.
        </span>
      </label>
    );
  }

  // text, name, company, title.
  return (
    <label htmlFor={inputId} className="block">
      <span className="text-sm font-medium">
        {label}
        {field.required ? <RequiredMark /> : null}
      </span>
      <input
        id={inputId}
        type="text"
        value={typeof value === "string" ? value : ""}
        autoComplete={autoCompleteFor(field)}
        onChange={(event) => onChange(event.target.value === "" ? null : event.target.value)}
        className="mt-1 block w-full min-h-11 rounded-md border border-black/20 bg-transparent px-3 text-base dark:border-white/20"
      />
    </label>
  );
}

function RequiredMark() {
  return (
    <span className="text-destructive ml-1" aria-hidden="true">
      *
    </span>
  );
}

function defaultLabel(field: FieldDefinition): string {
  switch (field.type) {
    case "signature":
      return "Your signature";
    case "initials":
      return "Your initials";
    case "name":
      return "Your printed name";
    case "company":
      return "Your organisation";
    case "title":
      return "Your job title";
    case "agreement_date":
      return "Agreement date";
    case "checkbox":
      return "Tick to confirm";
    default:
      return "Your answer";
  }
}

/**
 * Browser autofill hints, but only where the value is genuinely the browser's to know.
 *
 * Nothing on a contract should be autofilled from a saved form that happens to share a field
 * name, so `text` — free text on the agreement — gets no hint at all.
 */
function autoCompleteFor(field: FieldDefinition): string {
  switch (field.type) {
    case "name":
      return "name";
    case "company":
      return "organization";
    case "title":
      return "organization-title";
    default:
      return "off";
  }
}
