/**
 * The editor's document state: one field schema document, a selection, and an undo stack.
 *
 * Everything that changes the field set goes through this reducer, which is what makes
 * "editor -> JSON -> editor without drift" a property of the state machine rather than of
 * whichever component happened to write the last value. Three rules hold it up:
 *
 * 1. **Every coordinate is rounded once, on the way in**, with the schema's own
 *    `roundCoordinate`. State therefore always holds canonical numbers, so serialising it
 *    cannot move a rectangle and a round trip is byte-stable by construction rather than by
 *    a tolerance in a test.
 * 2. **No screen units reach this file.** A drag converts through `PageTransform` before it
 *    dispatches; a nudge is stated in points. There is no zoom level here to be stale.
 * 3. **History entries are documents, not diffs.** Undo restores a whole document, so an
 *    action that touches several fields (an import) undoes as one step and cannot leave the
 *    document half-way between two states.
 *
 * Clamping to the page is *optional and explicit*: an action carries `bounds` when the caller
 * has a page size to clamp against. Nothing is clamped implicitly, because a value typed into
 * a numeric input should be reported by the validator as `rect_out_of_page` rather than
 * silently changed to a different number than the one the sender typed.
 */

import type { FieldDefinition, FieldSchemaDocument, FieldType, PageSize, Rect } from "@/schema/fieldSchema";
import { roundCoordinate, serializeFieldSchema } from "@/schema/fieldSchema";

/** How many documents the in-memory undo stack keeps. Not persisted; a reload starts clean. */
export const HISTORY_LIMIT = 100;

/** Arrow-key step in points, and the shifted step. */
export const NUDGE_STEP = 1;

export const NUDGE_STEP_LARGE = 10;

export interface EditorState {
  /** The document as it is now. Always canonical numbers. */
  document: FieldSchemaDocument;
  /** The field the inspector and the keyboard act on, or null. */
  selectedFieldId: string | null;
  past: FieldSchemaDocument[];
  future: FieldSchemaDocument[];
  /** Canonical JSON of the last document the server acknowledged, for the dirty check. */
  savedJson: string;
}

export type EditorAction =
  | { type: "select"; fieldId: string | null }
  | { type: "add_field"; field: FieldDefinition }
  | { type: "move_field"; id: string; x: number; y: number; bounds?: PageSize | undefined }
  | { type: "nudge_field"; id: string; dx: number; dy: number; bounds?: PageSize | undefined }
  | { type: "resize_field"; id: string; rect: Rect; bounds?: PageSize | undefined }
  | { type: "update_field"; id: string; patch: FieldPatch }
  | { type: "duplicate_field"; id: string }
  | { type: "delete_field"; id: string }
  | { type: "import_document"; document: FieldSchemaDocument }
  | { type: "mark_saved"; document: FieldSchemaDocument }
  | { type: "undo" }
  | { type: "redo" };

/**
 * The properties the inspector and the table can change.
 *
 * `undefined` means "leave alone"; `null` means "remove", which is how an optional property
 * is cleared without a second action type. `id`, `recipient_id`, `type` and `page` are plain
 * values because they cannot be absent.
 */
export interface FieldPatch {
  recipient_id?: string;
  type?: FieldType;
  page?: number;
  rect?: Partial<Rect>;
  required?: boolean;
  read_only?: boolean;
  label?: string | null;
  alias?: string | null;
  prefillVariable?: string | null;
}

export function createEditorState(document: FieldSchemaDocument): EditorState {
  return {
    document,
    selectedFieldId: null,
    past: [],
    future: [],
    savedJson: serializeFieldSchema(document),
  };
}

/** True when the document differs from the last one the server acknowledged. */
export function isDirty(state: EditorState): boolean {
  return serializeFieldSchema(state.document) !== state.savedJson;
}

export function canUndo(state: EditorState): boolean {
  return state.past.length > 0;
}

export function canRedo(state: EditorState): boolean {
  return state.future.length > 0;
}

export function findField(document: FieldSchemaDocument, id: string): FieldDefinition | undefined {
  return document.fields.find((field) => field.id === id);
}

export function editorReducer(state: EditorState, action: EditorAction): EditorState {
  switch (action.type) {
    case "select":
      return state.selectedFieldId === action.fieldId
        ? state
        : { ...state, selectedFieldId: action.fieldId };

    case "add_field":
      return commit(
        withFields(state.document, [...state.document.fields, canonicaliseFieldNumbers(action.field)]),
        state,
        action.field.id,
      );

    case "move_field":
      return commit(
        mapField(state.document, action.id, (field) =>
          withRect(field, clamp({ ...field.rect, x: action.x, y: action.y }, action.bounds)),
        ),
        state,
      );

    case "nudge_field":
      return commit(
        mapField(state.document, action.id, (field) =>
          withRect(
            field,
            clamp({ ...field.rect, x: field.rect.x + action.dx, y: field.rect.y + action.dy }, action.bounds),
          ),
        ),
        state,
      );

    case "resize_field":
      return commit(
        mapField(state.document, action.id, (field) => withRect(field, clamp(action.rect, action.bounds))),
        state,
      );

    case "update_field":
      return commit(
        mapField(state.document, action.id, (field) => applyPatch(field, action.patch)),
        state,
      );

    case "duplicate_field": {
      const source = findField(state.document, action.id);

      if (source === undefined) {
        return state;
      }

      const copy = duplicateField(source, state.document);

      return commit(withFields(state.document, [...state.document.fields, copy]), state, copy.id);
    }

    case "delete_field": {
      if (findField(state.document, action.id) === undefined) {
        return state;
      }

      const next = commit(
        withFields(
          state.document,
          state.document.fields.filter((field) => field.id !== action.id),
        ),
        state,
      );

      return next.selectedFieldId === action.id ? { ...next, selectedFieldId: null } : next;
    }

    case "import_document": {
      const imported = commit(action.document, state);

      // An import replaces every field, so the previous selection is meaningless unless the
      // incoming document happens to carry the same id.
      return findField(imported.document, imported.selectedFieldId ?? "") === undefined
        ? { ...imported, selectedFieldId: null }
        : imported;
    }

    case "mark_saved":
      return {
        ...state,
        document: action.document,
        savedJson: serializeFieldSchema(action.document),
      };

    case "undo": {
      const previous = state.past.at(-1);

      if (previous === undefined) {
        return state;
      }

      return {
        ...state,
        document: previous,
        past: state.past.slice(0, -1),
        future: [state.document, ...state.future].slice(0, HISTORY_LIMIT),
        selectedFieldId: keepSelection(previous, state.selectedFieldId),
      };
    }

    case "redo": {
      const next = state.future[0];

      if (next === undefined) {
        return state;
      }

      return {
        ...state,
        document: next,
        past: [...state.past, state.document].slice(-HISTORY_LIMIT),
        future: state.future.slice(1),
        selectedFieldId: keepSelection(next, state.selectedFieldId),
      };
    }

    default:
      return state;
  }
}

/**
 * A blank field of the requested type, sized for its type and placed where the caller says.
 *
 * The default sizes are the ones the synthetic NDA fixture uses, so a field dropped on a page
 * looks like the fixture's rather than like a square nobody would ship.
 */
export function defaultRectFor(type: FieldType): Pick<Rect, "width" | "height"> {
  switch (type) {
    case "signature":
      return { width: 170, height: 36 };
    case "initials":
      return { width: 48, height: 24 };
    case "checkbox":
      return { width: 12, height: 12 };
    case "text":
      return { width: 220, height: 24 };
    default:
      return { width: 170, height: 18 };
  }
}

/**
 * A field id nothing in the document is using.
 *
 * Ids are API surface — they survive import and export byte-for-byte and templates address
 * fields by them — so they are readable and stable rather than random, and a collision is
 * resolved by counting rather than by overwriting.
 */
export function nextFieldId(document: FieldSchemaDocument, stem: string): string {
  const taken = new Set(document.fields.map((field) => field.id));

  if (!taken.has(stem)) {
    return stem;
  }

  let counter = 2;

  while (taken.has(`${stem}_${counter}`)) {
    counter += 1;
  }

  return `${stem}_${counter}`;
}

export function createField(
  document: FieldSchemaDocument,
  type: FieldType,
  recipientId: string,
  page: number,
  position: Pick<Rect, "x" | "y">,
): FieldDefinition {
  const size = defaultRectFor(type);

  return canonicaliseFieldNumbers({
    id: nextFieldId(document, `${recipientId}_${type}`.replace(/[^A-Za-z0-9._-]/g, "_")),
    recipient_id: recipientId,
    type,
    page,
    rect: { x: position.x, y: position.y, width: size.width, height: size.height },
    required: true,
    read_only: false,
  });
}

// ------------------------------------------------------------------------------- internals

function keepSelection(document: FieldSchemaDocument, selectedFieldId: string | null): string | null {
  return selectedFieldId !== null && findField(document, selectedFieldId) !== undefined
    ? selectedFieldId
    : null;
}

/**
 * Push a new document onto the history, unless it is identical to the present one.
 *
 * The comparison is on canonical bytes, which is the same equality the server applies when it
 * decides whether a PATCH changed anything, so a no-op edit — retyping the same number,
 * dragging a field back where it started — does not consume an undo step.
 */
function commit(
  document: FieldSchemaDocument,
  state: EditorState,
  select?: string,
): EditorState {
  if (serializeFieldSchema(document) === serializeFieldSchema(state.document)) {
    return select === undefined ? state : { ...state, selectedFieldId: select };
  }

  return {
    ...state,
    document,
    past: [...state.past, state.document].slice(-HISTORY_LIMIT),
    future: [],
    selectedFieldId: select ?? state.selectedFieldId,
  };
}

function withFields(document: FieldSchemaDocument, fields: FieldDefinition[]): FieldSchemaDocument {
  return { ...document, fields };
}

function mapField(
  document: FieldSchemaDocument,
  id: string,
  change: (field: FieldDefinition) => FieldDefinition,
): FieldSchemaDocument {
  return withFields(
    document,
    document.fields.map((field) => (field.id === id ? change(field) : field)),
  );
}

function withRect(field: FieldDefinition, rect: Rect): FieldDefinition {
  return { ...field, rect: roundRect(rect) };
}

function roundRect(rect: Rect): Rect {
  return {
    x: roundCoordinate(rect.x),
    y: roundCoordinate(rect.y),
    width: roundCoordinate(rect.width),
    height: roundCoordinate(rect.height),
  };
}

function canonicaliseFieldNumbers(field: FieldDefinition): FieldDefinition {
  return { ...field, rect: roundRect(field.rect) };
}

function clamp(rect: Rect, bounds: PageSize | undefined): Rect {
  if (bounds === undefined) {
    return rect;
  }

  const maxX = Math.max(0, bounds.width - rect.width);
  const maxY = Math.max(0, bounds.height - rect.height);

  return {
    x: Math.min(Math.max(rect.x, 0), maxX),
    y: Math.min(Math.max(rect.y, 0), maxY),
    width: rect.width,
    height: rect.height,
  };
}

function applyPatch(field: FieldDefinition, patch: FieldPatch): FieldDefinition {
  const next: FieldDefinition = { ...field };

  if (patch.recipient_id !== undefined) {
    next.recipient_id = patch.recipient_id;
  }

  if (patch.type !== undefined) {
    next.type = patch.type;
  }

  if (patch.page !== undefined) {
    next.page = patch.page;
  }

  if (patch.rect !== undefined) {
    next.rect = roundRect({ ...field.rect, ...patch.rect });
  }

  if (patch.required !== undefined) {
    next.required = patch.required;
  }

  if (patch.read_only !== undefined) {
    next.read_only = patch.read_only;
  }

  assignOptional(next, "label", patch.label);
  assignOptional(next, "alias", patch.alias);

  if (patch.prefillVariable !== undefined) {
    if (patch.prefillVariable === null || patch.prefillVariable === "") {
      delete next.prefill;
    } else {
      next.prefill = { variable: patch.prefillVariable };
    }
  }

  return next;
}

/**
 * Set an optional string property, or remove it.
 *
 * `exactOptionalPropertyTypes` is on, so an optional property is either absent or a string —
 * assigning `undefined` is a type error and would also serialise differently from an absent
 * property. Removing it is therefore a `delete`, not an assignment.
 */
function assignOptional(
  field: FieldDefinition,
  key: "label" | "alias",
  value: string | null | undefined,
): void {
  if (value === undefined) {
    return;
  }

  if (value === null || value === "") {
    delete field[key];

    return;
  }

  field[key] = value;
}

function duplicateField(source: FieldDefinition, document: FieldSchemaDocument): FieldDefinition {
  const copy: FieldDefinition = {
    ...source,
    id: nextFieldId(document, `${source.id}_copy`),
    rect: roundRect({ ...source.rect, x: source.rect.x + 12, y: source.rect.y + 12 }),
  };

  // An alias is unique within the document and addresses this field from outside it. Copying
  // one would make the document invalid (`duplicate_alias`) and, worse, would make two fields
  // answer to a name an integration uses to patch exactly one.
  delete copy.alias;

  // An anchor is dropped for the same shape of reason. Duplicating shifts the rectangle, but a
  // `replace` anchor overrides x and y at publish and send, so the copy would resolve straight
  // back on top of the original — two fields in one place, in a document whose canvas showed
  // two. The editor does not author anchors (docs/preparation/editor.md), so there is no control
  // here to re-aim the copy with; the honest result of duplicating an anchored field is a
  // hand-placed field at the shifted rectangle the user just saw.
  delete copy.anchor;

  return copy;
}
