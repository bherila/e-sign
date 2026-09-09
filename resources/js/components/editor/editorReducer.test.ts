import {
  type FieldSchemaDocument,
  parseFieldSchema,
  serializeFieldSchema,
  validateFieldSchema,
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

describe("a resolution receipt", () => {
  /**
   * A receipt is bound to the field's page and, in `replace` mode, to its exact rectangle, so
   * editing either makes it a record of resolving something else. The importer refuses that, and
   * the editor offers no way to delete a receipt by hand — so keeping one through a drag would
   * make Save a 422 with no way out. The anchor *request* is kept: publishing resolves it again.
   */
  function resolved(): EditorState {
    const raw = JSON.parse(JSON.stringify(fixtureJson)) as Record<string, any>;
    raw.fields[5].anchor.resolved = {
      document_sha256: "d".repeat(64),
      page: 2,
      occurrence_index: 1,
      anchor_rect: { x: 330, y: 622.4, width: 165.6, height: 12 },
      rect: raw.fields[5].rect,
    };

    return createEditorState(parseFieldSchema(raw));
  }

  it("survives a document that is only read", () => {
    expect(findField(resolved().document, "counterparty_signature")?.anchor?.resolved).toBeDefined();
  });

  it.each([
    [
      "moving the field",
      { type: "move_field", id: "counterparty_signature", rect: { x: 340, y: 650, width: 170, height: 36 } },
    ],
    [
      "patching its rectangle",
      { type: "update_field", id: "counterparty_signature", patch: { rect: { x: 340 } } },
    ],
    [
      "patching its page",
      { type: "update_field", id: "counterparty_signature", patch: { page: 1 } },
    ],
  ] as const)("is dropped by %s, and the request is kept", (_name, action) => {
    const next = editorReducer(resolved(), action as Parameters<typeof editorReducer>[1]);
    const field = findField(next.document, "counterparty_signature");

    expect(field?.anchor?.resolved).toBeUndefined();
    expect(field?.anchor?.text).toBe("Counterparty signature:");
  });

  it("is not copied onto a duplicate, which sits somewhere else entirely", () => {
    const next = editorReducer(resolved(), { type: "duplicate_field", id: "counterparty_signature" });
    const copy = findField(next.document, "counterparty_signature_copy");

    expect(copy?.anchor?.resolved).toBeUndefined();
    expect(copy?.anchor?.text).toBe("Counterparty signature:");
  });

  /**
   * The rule is the result, not the action: a gesture that changes nothing invalidates nothing.
   *
   * `FieldBox.finish()` reports a pointer-up as a move even when the displacement is zero, so a
   * plain selection click arrives here as `move_field`. Deciding invalidation from the action
   * deleted the receipt, marked the document dirty and pushed an undo entry for a click.
   */
  it("survives a move that does not move it", () => {
    const start = resolved();
    const at = findField(start.document, "counterparty_signature")!;
    const next = editorReducer(start, {
      type: "move_field",
      id: "counterparty_signature",
      x: at.rect.x,
      y: at.rect.y,
    });

    expect(findField(next.document, "counterparty_signature")?.anchor?.resolved).toBeDefined();
    expect(isDirty(next)).toBe(false);
    expect(canUndo(next)).toBe(false);
  });

  it("survives a patch that sets the rectangle to what it already is", () => {
    const start = resolved();
    const at = findField(start.document, "counterparty_signature")!;
    const next = editorReducer(start, {
      type: "update_field",
      id: "counterparty_signature",
      patch: { rect: { x: at.rect.x, y: at.rect.y } },
    });

    expect(findField(next.document, "counterparty_signature")?.anchor?.resolved).toBeDefined();
  });

  it("survives an edit that cannot invalidate it", () => {
    const next = editorReducer(resolved(), {
      type: "update_field",
      id: "counterparty_signature",
      patch: { label: "Counterparty" },
    });

    expect(findField(next.document, "counterparty_signature")?.anchor?.resolved).toBeDefined();
  });
});

describe("no editor action can produce an unsavable document", () => {
  /**
   * The invariant, stated once: whatever sequence of actions the UI allows, the result validates.
   *
   * The contract has cross-property rules — a receipt is bound to its field's rectangle, and an
   * optional anchor is only allowed on an optional field — and the inspector exposes a control
   * for one side of each pair and not the other. So the reducer owns the consequence: an edit
   * that breaks a pair has to repair it, or the editor can reach a state Save refuses with no
   * control able to undo it.
   */
  it("keeps the anchor's requiredness coherent when the field becomes required", () => {
    // Field 9 is the optional notes field whose anchor may legitimately be absent.
    const before = findField(state().document, "counterparty_notes");
    expect(before?.required).toBe(false);
    expect(before?.anchor?.required).toBe(false);

    const next = editorReducer(state(), {
      type: "update_field",
      id: "counterparty_notes",
      patch: { required: true },
    });
    const field = findField(next.document, "counterparty_notes");

    expect(field?.required).toBe(true);
    expect(field?.anchor?.required).toBeUndefined();
    expect(field?.anchor?.text).toBe("Notes:");
    expect(validateFieldSchema(JSON.parse(serializeFieldSchema(next.document)))).toEqual([]);
  });

  it("leaves an optional anchor alone when the field stays optional", () => {
    const next = editorReducer(state(), {
      type: "update_field",
      id: "counterparty_notes",
      patch: { label: "Notes" },
    });

    expect(findField(next.document, "counterparty_notes")?.anchor?.required).toBe(false);
  });

  it("still validates after a drag, a page change and a requiredness toggle in sequence", () => {
    const next = apply(
      state(),
      { type: "update_field", id: "counterparty_notes", patch: { required: true } },
      { type: "move_field", id: "counterparty_signature", x: 340, y: 660 },
      { type: "update_field", id: "counterparty_signature", patch: { page: 1 } },
      { type: "duplicate_field", id: "counterparty_signature" },
    );

    expect(validateFieldSchema(JSON.parse(serializeFieldSchema(next.document)))).toEqual([]);
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
