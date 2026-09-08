import {
  fieldEntryReducer,
  type FieldEntryState,
  hasValue,
  initialFieldEntryState,
  missingFieldIds,
  pendingValues,
  readyToSign,
} from "./fieldEntryReducer";
import type { ReviewedState } from "./types";

/**
 * The signing form's state machine.
 *
 * Two of these tests are the ones that matter, and both are about the same failure: a page
 * that lets somebody sign a document that differs from the one in front of them. The server
 * binds an acceptance to the values it holds, so an unsaved edit on screen at the moment of
 * signing is a signature on unseen text — which `readyToSign` refuses, and which
 * `save_succeeded` must not paper over by clearing edits the server never confirmed.
 */

const REVIEWED: ReviewedState = {
  material_values_sha256: "a".repeat(64),
  envelope_version: 3,
};

const NEXT_REVIEWED: ReviewedState = {
  material_values_sha256: "b".repeat(64),
  envelope_version: 4,
};

function state(overrides: Partial<FieldEntryState> = {}): FieldEntryState {
  return {
    ...initialFieldEntryState(
      { buyer_ack: null, buyer_notes: null, seller_title: "Director" },
      ["buyer_ack", "buyer_notes", "buyer_signature"],
      REVIEWED,
    ),
    ...overrides,
  };
}

describe("set", () => {
  it("records a value the recipient owns and marks it dirty", () => {
    const next = fieldEntryReducer(state(), { type: "set", fieldId: "buyer_ack", value: true });

    expect(next.values.buyer_ack).toBe(true);
    expect(next.dirty).toEqual(["buyer_ack"]);
  });

  it("ignores a field the recipient does not own", () => {
    const before = state();
    const next = fieldEntryReducer(before, {
      type: "set",
      fieldId: "seller_title",
      value: "Impostor",
    });

    // Not an error the signer sees: no control on the page can produce it. The server refuses
    // it regardless (invariant 1); what this prevents is the *displayed* document diverging
    // from the one that would be submitted.
    expect(next).toBe(before);
    expect(next.values.seller_title).toBe("Director");
  });

  it("ignores an unknown field", () => {
    const before = state();

    expect(fieldEntryReducer(before, { type: "set", fieldId: "nope", value: "x" })).toBe(before);
  });

  it("does not mark a field dirty when the value did not change", () => {
    const first = fieldEntryReducer(state(), { type: "set", fieldId: "buyer_notes", value: "Hi" });
    const again = fieldEntryReducer(first, { type: "set", fieldId: "buyer_notes", value: "Hi" });

    expect(again).toBe(first);
    expect(again.dirty).toEqual(["buyer_notes"]);
  });

  it("marks a field dirty once, however often it is edited", () => {
    let current = state();

    for (const value of ["a", "b", "c"]) {
      current = fieldEntryReducer(current, { type: "set", fieldId: "buyer_notes", value });
    }

    expect(current.dirty).toEqual(["buyer_notes"]);
    expect(current.values.buyer_notes).toBe("c");
  });

  it("clears a previous failure, because the signer has done something about it", () => {
    const failed = fieldEntryReducer(state(), { type: "save_failed", message: "Nope" });
    const edited = fieldEntryReducer(failed, { type: "set", fieldId: "buyer_ack", value: true });

    expect(failed.error).toBe("Nope");
    expect(edited.error).toBeNull();
  });
});

describe("saving", () => {
  it("clears only the fields the server confirmed", () => {
    let current = state();
    current = fieldEntryReducer(current, { type: "set", fieldId: "buyer_ack", value: true });
    current = fieldEntryReducer(current, { type: "set", fieldId: "buyer_notes", value: "Note" });
    current = fieldEntryReducer(current, { type: "save_started" });
    current = fieldEntryReducer(current, {
      type: "save_succeeded",
      fieldIds: ["buyer_ack"],
      reviewed: NEXT_REVIEWED,
    });

    // A page that cleared `buyer_notes` here would believe it had persisted something it had
    // not, and would then offer the signer a button to sign it.
    expect(current.dirty).toEqual(["buyer_notes"]);
    expect(current.saving).toBe(false);
  });

  it("keeps an edit made while the request was in flight", () => {
    let current = state();
    current = fieldEntryReducer(current, { type: "set", fieldId: "buyer_ack", value: true });
    current = fieldEntryReducer(current, { type: "save_started" });
    current = fieldEntryReducer(current, { type: "set", fieldId: "buyer_notes", value: "Late" });
    current = fieldEntryReducer(current, {
      type: "save_succeeded",
      fieldIds: ["buyer_ack"],
      reviewed: NEXT_REVIEWED,
    });

    expect(current.dirty).toEqual(["buyer_notes"]);
  });

  it("takes the review only from the server", () => {
    const saved = fieldEntryReducer(state(), {
      type: "save_succeeded",
      fieldIds: [],
      reviewed: NEXT_REVIEWED,
    });

    expect(saved.reviewed).toEqual(NEXT_REVIEWED);
  });

  it("leaves the review alone when a save fails", () => {
    let current = state();
    current = fieldEntryReducer(current, { type: "set", fieldId: "buyer_ack", value: true });
    current = fieldEntryReducer(current, { type: "save_failed", message: "Offline" });

    expect(current.reviewed).toEqual(REVIEWED);
    expect(current.dirty).toEqual(["buyer_ack"]);
    expect(current.error).toBe("Offline");
    expect(current.saving).toBe(false);
  });

  it("dismisses an error without touching anything else", () => {
    const failed = fieldEntryReducer(state(), { type: "save_failed", message: "Offline" });
    const dismissed = fieldEntryReducer(failed, { type: "dismiss_error" });

    expect(dismissed.error).toBeNull();
    expect(dismissed.values).toEqual(failed.values);
  });
});

describe("hasValue", () => {
  it("treats an unticked checkbox as no value", () => {
    expect(hasValue(false)).toBe(false);
    expect(hasValue(true)).toBe(true);
  });

  it("treats whitespace as no value", () => {
    expect(hasValue("   ")).toBe(false);
    expect(hasValue("x")).toBe(true);
  });

  it("treats null as no value", () => {
    expect(hasValue(null)).toBe(false);
  });
});

describe("readiness", () => {
  const required = ["buyer_ack", "buyer_signature"];

  it("lists what is still missing", () => {
    expect(missingFieldIds(state(), required)).toEqual(["buyer_ack", "buyer_signature"]);
  });

  it("refuses while a required field is empty", () => {
    expect(readyToSign(state(), required)).toBe(false);
  });

  it("refuses while anything is unsaved, even with everything filled in", () => {
    let current = state();
    current = fieldEntryReducer(current, { type: "set", fieldId: "buyer_ack", value: true });
    current = fieldEntryReducer(current, {
      type: "set",
      fieldId: "buyer_signature",
      value: "data:image/png;base64,AAAA",
    });

    // Everything is filled in and nothing has reached the server. Signing now would bind the
    // signer to the document as the *server* holds it, which is not the one on screen.
    expect(missingFieldIds(current, required)).toEqual([]);
    expect(readyToSign(current, required)).toBe(false);
  });

  it("allows signing once everything is filled in and saved", () => {
    let current = state();
    current = fieldEntryReducer(current, { type: "set", fieldId: "buyer_ack", value: true });
    current = fieldEntryReducer(current, {
      type: "set",
      fieldId: "buyer_signature",
      value: "data:image/png;base64,AAAA",
    });
    current = fieldEntryReducer(current, {
      type: "save_succeeded",
      fieldIds: ["buyer_ack", "buyer_signature"],
      reviewed: NEXT_REVIEWED,
    });

    expect(readyToSign(current, required)).toBe(true);
  });
});

describe("pendingValues", () => {
  it("sends only what changed", () => {
    let current = state();
    current = fieldEntryReducer(current, { type: "set", fieldId: "buyer_notes", value: "Note" });

    // Sending every field the signer owns would rewrite untouched values on every save,
    // bumping the envelope version and invalidating other parties' reviews for no reason.
    expect(pendingValues(current)).toEqual({ buyer_notes: "Note" });
  });

  it("sends nothing when nothing changed", () => {
    expect(pendingValues(state())).toEqual({});
  });

  it("sends a cleared field as null", () => {
    let current = state();
    current = fieldEntryReducer(current, { type: "set", fieldId: "buyer_notes", value: "Note" });
    current = fieldEntryReducer(current, {
      type: "save_succeeded",
      fieldIds: ["buyer_notes"],
      reviewed: NEXT_REVIEWED,
    });
    current = fieldEntryReducer(current, { type: "set", fieldId: "buyer_notes", value: null });

    expect(pendingValues(current)).toEqual({ buyer_notes: null });
  });
});
