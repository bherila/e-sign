import {
  type FieldSchemaDocument,
  parseFieldSchema,
  serializeFieldSchema,
} from "@/schema/fieldSchema";

import fixtureJson from "../../../../tests/Fixtures/schema/nda-two-signers.json";
import {
  canRedo,
  canUndo,
  createEditorState,
  createField,
  defaultRectFor,
  editorReducer,
  type EditorState,
  findField,
  HISTORY_LIMIT,
  isDirty,
  nextFieldId,
  NUDGE_STEP,
  NUDGE_STEP_LARGE,
} from "./editorReducer";

/**
 * The document half of the editor.
 *
 * The invariant every case here defends is the issue's acceptance criterion: editor -> JSON ->
 * editor without drift. The reducer holds it by keeping state canonical at all times, so
 * serialising at any point produces the bytes the server would have stored — which is asserted
 * directly rather than with a tolerance.
 *
 * The fixture is the synthetic NDA both suites share; nothing here invents a document shape.
 */

/** Letter portrait, the size the fixture is placed against. */
const LETTER = { width: 612, height: 792 };

/**
 * A fresh copy each time. `parseFieldSchema` canonicalises rather than mutating, but the
 * imported JSON module is one shared object graph, so a test that reached into it would leak
 * into the next one.
 */
function fixture(): FieldSchemaDocument {
  return parseFieldSchema(JSON.parse(JSON.stringify(fixtureJson)) as unknown);
}

function state(): EditorState {
  return createEditorState(fixture());
}

function apply(initial: EditorState, ...actions: Parameters<typeof editorReducer>[1][]): EditorState {
  return actions.reduce(editorReducer, initial);
}

describe("round trip", () => {
  it("starts canonical, so the state serialises to exactly the stored bytes", () => {
    const start = state();

    expect(serializeFieldSchema(start.document)).toBe(serializeFieldSchema(fixture()));
    expect(isDirty(start)).toBe(false);
  });

  it("survives export and re-import after a sequence of edits, byte for byte", () => {
    const edited = apply(
      state(),
      { type: "move_field", id: "buyer_signature", x: 61.5, y: 651.25 },
      { type: "resize_field", id: "buyer_initials", rect: { x: 500, y: 730, width: 52, height: 26 } },
      { type: "nudge_field", id: "counterparty_notes", dx: NUDGE_STEP_LARGE, dy: -NUDGE_STEP },
      { type: "update_field", id: "buyer_company", patch: { label: "Buyer company" } },
      { type: "duplicate_field", id: "buyer_confidentiality_ack" },
    );

    const exported = serializeFieldSchema(edited.document);
    const reimported = parseFieldSchema(exported);

    expect(serializeFieldSchema(reimported)).toBe(exported);
    expect(reimported).toEqual(edited.document);
  });

  it("rounds a coordinate once, on the way in, rather than at export time", () => {
    const moved = editorReducer(state(), {
      type: "move_field",
      id: "buyer_signature",
      x: 60.00049,
      y: 650.0005,
    });

    // Half away from zero at three decimals, exactly as `roundCoordinate` and PHP's round() do.
    expect(findField(moved.document, "buyer_signature")?.rect).toEqual({
      x: 60,
      y: 650.001,
      width: 170,
      height: 36,
    });
  });
});

describe("placing and sizing", () => {
  it("adds a field and selects it", () => {
    const start = state();
    const field = createField(start.document, "text", "buyer", 1, { x: 100, y: 200 });
    const next = editorReducer(start, { type: "add_field", field });

    expect(next.document.fields).toHaveLength(start.document.fields.length + 1);
    expect(next.selectedFieldId).toBe(field.id);
    expect(findField(next.document, field.id)?.rect).toEqual({
      x: 100,
      y: 200,
      ...defaultRectFor("text"),
    });
  });

  it("gives a new field an id nothing else in the document holds", () => {
    const start = state();
    const first = createField(start.document, "signature", "buyer", 1, { x: 0, y: 0 });

    expect(first.id).toBe("buyer_signature_2");
    expect(nextFieldId(start.document, "buyer_signature")).toBe("buyer_signature_2");
  });

  it("moves a field to an absolute position", () => {
    const next = editorReducer(state(), { type: "move_field", id: "buyer_signature", x: 90, y: 120 });

    expect(findField(next.document, "buyer_signature")?.rect).toEqual({
      x: 90,
      y: 120,
      width: 170,
      height: 36,
    });
  });

  it("keeps a move inside the page when the caller supplies the page size", () => {
    const next = editorReducer(state(), {
      type: "move_field",
      id: "buyer_signature",
      x: 600,
      y: 900,
      bounds: LETTER,
    });

    expect(findField(next.document, "buyer_signature")?.rect).toEqual({
      x: 442,
      y: 756,
      width: 170,
      height: 36,
    });
  });

  it("does not clamp when no page size is supplied, so the validator can say so", () => {
    const next = editorReducer(state(), { type: "move_field", id: "buyer_signature", x: 600, y: 900 });

    expect(findField(next.document, "buyer_signature")?.rect.x).toBe(600);
  });

  it("resizes a field", () => {
    const next = editorReducer(state(), {
      type: "resize_field",
      id: "buyer_signature",
      rect: { x: 60, y: 650, width: 200, height: 40 },
    });

    expect(findField(next.document, "buyer_signature")?.rect).toEqual({
      x: 60,
      y: 650,
      width: 200,
      height: 40,
    });
  });

  it("nudges by one point, and by ten with shift", () => {
    const once = editorReducer(state(), {
      type: "nudge_field",
      id: "buyer_signature",
      dx: NUDGE_STEP,
      dy: 0,
    });
    const far = editorReducer(once, {
      type: "nudge_field",
      id: "buyer_signature",
      dx: 0,
      dy: -NUDGE_STEP_LARGE,
    });

    expect(findField(once.document, "buyer_signature")?.rect.x).toBe(61);
    expect(findField(far.document, "buyer_signature")?.rect).toEqual({
      x: 61,
      y: 640,
      width: 170,
      height: 36,
    });
  });

  it("ignores an action naming a field that is not there", () => {
    const start = state();

    expect(editorReducer(start, { type: "move_field", id: "nobody", x: 1, y: 1 })).toBe(start);
    expect(editorReducer(start, { type: "delete_field", id: "nobody" })).toBe(start);
    expect(editorReducer(start, { type: "duplicate_field", id: "nobody" })).toBe(start);
  });
});

describe("duplicate and delete", () => {
  it("offsets a duplicate, gives it a fresh id, and drops the alias", () => {
    const next = editorReducer(state(), { type: "duplicate_field", id: "buyer_signature" });
    const copy = findField(next.document, "buyer_signature_copy");

    expect(copy).toBeDefined();
    expect(copy?.rect).toEqual({ x: 72, y: 662, width: 170, height: 36 });
    // The original carries alias "buyer_signature_block"; two fields cannot share one.
    expect(copy?.alias).toBeUndefined();
    expect(next.selectedFieldId).toBe("buyer_signature_copy");
    expect(serializeFieldSchema(parseFieldSchema(serializeFieldSchema(next.document)))).toBe(
      serializeFieldSchema(next.document),
    );
  });

  it("clears the selection when the selected field is deleted", () => {
    const selected = apply(state(), { type: "select", fieldId: "buyer_initials" });
    const deleted = editorReducer(selected, { type: "delete_field", id: "buyer_initials" });

    expect(findField(deleted.document, "buyer_initials")).toBeUndefined();
    expect(deleted.selectedFieldId).toBeNull();
  });
});

describe("undo and redo", () => {
  it("restores the previous document and then the next one", () => {
    const start = state();
    const moved = editorReducer(start, { type: "move_field", id: "buyer_signature", x: 90, y: 120 });
    const undone = editorReducer(moved, { type: "undo" });
    const redone = editorReducer(undone, { type: "redo" });

    expect(canUndo(start)).toBe(false);
    expect(canUndo(moved)).toBe(true);
    expect(serializeFieldSchema(undone.document)).toBe(serializeFieldSchema(start.document));
    expect(canRedo(undone)).toBe(true);
    expect(serializeFieldSchema(redone.document)).toBe(serializeFieldSchema(moved.document));
  });

  it("undoes an import as a single step", () => {
    const start = state();
    const imported = editorReducer(start, {
      type: "import_document",
      document: { ...start.document, fields: [] },
    });
    const undone = editorReducer(imported, { type: "undo" });

    expect(imported.document.fields).toHaveLength(0);
    expect(imported.selectedFieldId).toBeNull();
    expect(serializeFieldSchema(undone.document)).toBe(serializeFieldSchema(start.document));
  });

  it("does not record a step for an edit that changes nothing", () => {
    const start = state();
    const same = editorReducer(start, { type: "move_field", id: "buyer_signature", x: 60, y: 650 });

    expect(same.past).toHaveLength(0);
    expect(canUndo(same)).toBe(false);
  });

  it("drops the redo stack once a new edit is made", () => {
    const moved = editorReducer(state(), { type: "move_field", id: "buyer_signature", x: 90, y: 120 });
    const undone = editorReducer(moved, { type: "undo" });
    const diverged = editorReducer(undone, { type: "move_field", id: "buyer_initials", x: 10, y: 10 });

    expect(canRedo(diverged)).toBe(false);
  });

  it("keeps the history bounded", () => {
    let current = state();

    for (let step = 1; step <= HISTORY_LIMIT + 20; step += 1) {
      current = editorReducer(current, {
        type: "move_field",
        id: "buyer_signature",
        x: 60 + step,
        y: 650,
      });
    }

    expect(current.past).toHaveLength(HISTORY_LIMIT);
  });

  it("drops a selection that the restored document does not contain", () => {
    const start = state();
    const field = createField(start.document, "text", "buyer", 1, { x: 10, y: 10 });
    const added = editorReducer(start, { type: "add_field", field });
    const undone = editorReducer(added, { type: "undo" });

    expect(added.selectedFieldId).toBe(field.id);
    expect(undone.selectedFieldId).toBeNull();
  });
});

describe("patching a field", () => {
  it("changes the type, the recipient, the page and the flags", () => {
    const next = editorReducer(state(), {
      type: "update_field",
      id: "buyer_signature",
      patch: { type: "initials", recipient_id: "counterparty", page: 1, required: false, read_only: true },
    });
    const field = findField(next.document, "buyer_signature");

    expect(field).toMatchObject({
      type: "initials",
      recipient_id: "counterparty",
      page: 1,
      required: false,
      read_only: true,
    });
  });

  it("removes an optional property when it is cleared, rather than storing an empty string", () => {
    const cleared = editorReducer(state(), {
      type: "update_field",
      id: "buyer_signature",
      patch: { label: "", alias: null },
    });
    const field = findField(cleared.document, "buyer_signature");

    expect(field).toBeDefined();
    expect("label" in (field ?? {})).toBe(false);
    expect("alias" in (field ?? {})).toBe(false);
    expect(serializeFieldSchema(cleared.document)).not.toContain('"label":""');
  });

  it("sets and clears a prefill variable", () => {
    const set = editorReducer(state(), {
      type: "update_field",
      id: "buyer_signature",
      patch: { prefillVariable: "recipient.name" },
    });
    const cleared = editorReducer(set, {
      type: "update_field",
      id: "buyer_signature",
      patch: { prefillVariable: "" },
    });

    expect(findField(set.document, "buyer_signature")?.prefill).toEqual({ variable: "recipient.name" });
    expect(findField(cleared.document, "buyer_signature")?.prefill).toBeUndefined();
  });

  it("merges a partial rectangle onto the existing one", () => {
    const next = editorReducer(state(), {
      type: "update_field",
      id: "buyer_signature",
      patch: { rect: { width: 180 } },
    });

    expect(findField(next.document, "buyer_signature")?.rect).toEqual({
      x: 60,
      y: 650,
      width: 180,
      height: 36,
    });
  });
});

describe("dirty tracking", () => {
  it("is clean until something changes and clean again once the server acknowledges it", () => {
    const start = state();
    const moved = editorReducer(start, { type: "move_field", id: "buyer_signature", x: 90, y: 120 });
    const saved = editorReducer(moved, { type: "mark_saved", document: moved.document });

    expect(isDirty(start)).toBe(false);
    expect(isDirty(moved)).toBe(true);
    expect(isDirty(saved)).toBe(false);
  });

  it("is clean again after undoing back to the saved document", () => {
    const moved = editorReducer(state(), { type: "move_field", id: "buyer_signature", x: 90, y: 120 });

    expect(isDirty(editorReducer(moved, { type: "undo" }))).toBe(false);
  });
});
