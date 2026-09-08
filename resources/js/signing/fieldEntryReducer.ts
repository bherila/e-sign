/**
 * The signing form's state, as one reducer.
 *
 * A signing page has more moving parts than it looks: values the signer is typing, values
 * other parties already supplied, which of their own edits have reached the server, whether a
 * save is in flight, and the review digest the acceptance will be bound to. Spreading those
 * across half a dozen `useState` calls is how a page ends up letting somebody sign with an
 * unsaved edit on screen — the form looks complete and the database disagrees.
 *
 * Two rules are worth stating because they are the ones a test pins:
 *
 * 1. **A field the signer does not own cannot be changed here.** The server enforces
 *    ownership regardless (`EnvelopeStateMachine::submitValues()`, docs/ARCHITECTURE.md
 *    invariant 1), so this is not the security boundary — it is what stops the *displayed*
 *    document diverging from the one that will be submitted, which would be its own kind of
 *    lie.
 * 2. **`reviewed` only ever comes from the server.** It is refreshed by a successful save and
 *    by nothing else. Recomputing it on the client would satisfy the staleness check and
 *    defeat its entire purpose.
 */

import type { FieldValue, ReviewedState } from "./types";

export interface FieldEntryState {
  /** Every value on the agreement, the signer's own and everybody else's. */
  values: Readonly<Record<string, FieldValue>>;
  /** Field ids this recipient may write. Fixed for the life of the page. */
  ownFieldIds: readonly string[];
  /** Own fields edited since the last successful save, in the order they were first edited. */
  dirty: readonly string[];
  saving: boolean;
  error: string | null;
  reviewed: ReviewedState;
}

export type FieldEntryAction =
  | { type: "set"; fieldId: string; value: FieldValue }
  | { type: "save_started" }
  | { type: "save_succeeded"; fieldIds: readonly string[]; reviewed: ReviewedState }
  | { type: "save_failed"; message: string }
  | { type: "dismiss_error" };

export function initialFieldEntryState(
  values: Record<string, FieldValue>,
  ownFieldIds: readonly string[],
  reviewed: ReviewedState,
): FieldEntryState {
  return {
    values: { ...values },
    ownFieldIds: [...ownFieldIds],
    dirty: [],
    saving: false,
    error: null,
    reviewed,
  };
}

export function fieldEntryReducer(
  state: FieldEntryState,
  action: FieldEntryAction,
): FieldEntryState {
  switch (action.type) {
    case "set": {
      if (!state.ownFieldIds.includes(action.fieldId)) {
        // Not an error the signer needs to see: no control on the page can produce it, so
        // reaching here means a bug, and the safe response is to change nothing.
        return state;
      }

      const current = state.values[action.fieldId] ?? null;
      const next = action.value;

      if (current === next) {
        // Re-selecting the same option, or a controlled input echoing its own value, is not
        // an edit. Marking it dirty would make the Save button light up for nothing and would
        // resubmit a value the server already has.
        return state;
      }

      return {
        ...state,
        values: { ...state.values, [action.fieldId]: next },
        dirty: state.dirty.includes(action.fieldId)
          ? state.dirty
          : [...state.dirty, action.fieldId],
        // An edit invalidates the last failure: the signer has done something about it.
        error: null,
      };
    }

    case "save_started":
      return { ...state, saving: true, error: null };

    case "save_succeeded": {
      // Only the fields the server confirms are cleared. An edit made *while* the request was
      // in flight is still unsaved, and dropping it from `dirty` here is exactly how a page
      // comes to believe it has persisted something it has not.
      const saved = new Set(action.fieldIds);

      return {
        ...state,
        saving: false,
        error: null,
        dirty: state.dirty.filter((fieldId) => !saved.has(fieldId)),
        reviewed: action.reviewed,
      };
    }

    case "save_failed":
      // `dirty` is untouched: the values are still only in the browser, and the next save has
      // to send them again.
      return { ...state, saving: false, error: action.message };

    case "dismiss_error":
      return { ...state, error: null };

    default:
      return state;
  }
}

/** Whether a value counts as supplied. Empty strings and `false` are not. */
export function hasValue(value: FieldValue): boolean {
  if (value === null) {
    return false;
  }

  if (typeof value === "boolean") {
    return value;
  }

  return value.trim() !== "";
}

/** Ids among `fieldIds` that still have no value. */
export function missingFieldIds(
  state: FieldEntryState,
  fieldIds: readonly string[],
): string[] {
  return fieldIds.filter((fieldId) => !hasValue(state.values[fieldId] ?? null));
}

/**
 * Whether the signer may be offered the accept button.
 *
 * Everything required has a value *and* nothing is waiting to be saved. The second half is
 * the one that matters: the server binds the acceptance to the values it holds, so signing
 * with an unsaved edit on screen would bind the signer to a document that differs from the
 * one in front of them.
 */
export function readyToSign(
  state: FieldEntryState,
  requiredFieldIds: readonly string[],
): boolean {
  return state.dirty.length === 0 && missingFieldIds(state, requiredFieldIds).length === 0;
}

/**
 * The subset of values to send: the signer's own, and only the ones that changed.
 *
 * Sending everything they own would rewrite untouched fields on every save, bumping the
 * envelope version and invalidating other parties' reviews for no reason.
 */
export function pendingValues(state: FieldEntryState): Record<string, FieldValue> {
  const pending: Record<string, FieldValue> = {};

  for (const fieldId of state.dirty) {
    pending[fieldId] = state.values[fieldId] ?? null;
  }

  return pending;
}
