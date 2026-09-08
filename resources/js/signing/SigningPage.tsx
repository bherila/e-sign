import { useCallback, useMemo, useReducer, useRef } from "react";

import { type FieldDefinition, type FieldSchemaDocument, parseFieldSchema } from "@/schema/fieldSchema";

import { saveValues, SigningApiError } from "./api";
import { ConsentPanel } from "./ConsentPanel";
import { DocumentReview } from "./DocumentReview";
import { FieldEntry } from "./FieldEntry";
import {
  fieldEntryReducer,
  initialFieldEntryState,
  missingFieldIds,
  pendingValues,
  readyToSign,
} from "./fieldEntryReducer";
import type { SigningPayload } from "./types";

/**
 * The signer's whole page: the document, the fields they own, and the decision.
 *
 * Laid out as one column in reading order — read it, fill it in, then agree — rather than as
 * a document pane beside a form. On a 375px phone a two-pane layout collapses into one anyway
 * and the collapse decides the order for you; deciding it deliberately means the order is the
 * same on every screen, and it is the order the act itself has: nobody agrees to a contract
 * before reading it.
 *
 * ## Saving
 *
 * Values are saved explicitly and the accept button stays disabled until every one of them
 * has landed. Autosaving on every keystroke was the alternative and is worse here: each save
 * bumps the envelope version, which invalidates every other party's in-flight review
 * (docs/signing/state-machine.md), so a signer typing a paragraph into a text field would
 * force everybody else to re-read the agreement a hundred times.
 *
 * `readyToSign` is what enforces the important half of that. The server binds an acceptance
 * to the values it holds, so signing with an unsaved edit on screen would bind somebody to a
 * document that differs from the one in front of them.
 *
 * ## Where the authority is
 *
 * Nowhere here. Ownership, eligibility, types, image re-encoding, the freeze, and the
 * staleness check are all server-side, and every one of them refuses independently of what
 * this component believes. What this component owes the signer is that the page in front of
 * them describes what will happen — not that it can prevent anything.
 */
export interface SigningPageProps {
  payload: SigningPayload;
}

export function SigningPage({ payload }: SigningPageProps) {
  const [state, dispatch] = useReducer(
    fieldEntryReducer,
    initialFieldEntryState(payload.values, payload.own_field_ids, payload.reviewed),
  );
  const entries = useRef<Map<string, HTMLDivElement>>(new Map());

  const schema = useMemo<FieldSchemaDocument | null>(() => {
    try {
      return parseFieldSchema(payload.field_schema);
    } catch {
      return null;
    }
  }, [payload.field_schema]);

  const ownFields = useMemo<FieldDefinition[]>(
    () =>
      (schema?.fields ?? []).filter((field) => payload.own_field_ids.includes(field.id)),
    [schema, payload.own_field_ids],
  );

  const requiredFieldIds = useMemo<string[]>(
    () => ownFields.filter((field) => field.required).map((field) => field.id),
    [ownFields],
  );

  const save = useCallback(async (): Promise<void> => {
    const pending = pendingValues(state);

    if (Object.keys(pending).length === 0) {
      return;
    }

    dispatch({ type: "save_started" });

    try {
      const result = await saveValues(payload.urls.values, payload.csrf_token, pending);
      dispatch({ type: "save_succeeded", fieldIds: result.field_ids, reviewed: result.reviewed });
    } catch (cause) {
      dispatch({
        type: "save_failed",
        message:
          cause instanceof SigningApiError
            ? cause.message
            : "Your changes could not be saved. Nothing has been lost — try again.",
      });
    }
  }, [payload.urls.values, payload.csrf_token, state]);

  const focusField = useCallback((fieldId: string): void => {
    const element = entries.current.get(fieldId);

    element?.scrollIntoView({ block: "center" });
    element?.querySelector<HTMLElement>("input, textarea, button")?.focus();
  }, []);

  if (schema === null) {
    return (
      <p className="text-destructive rounded-md border border-destructive/40 p-4 text-sm" role="alert">
        This agreement's field layout could not be read, so the page cannot show you what you
        are being asked to complete. Nothing has been signed. Reload the page, and tell the
        sender if it happens again.
      </p>
    );
  }

  const missing = missingFieldIds(state, requiredFieldIds);
  const ready = readyToSign(state, requiredFieldIds);

  return (
    <div>
      <header>
        <h1 className="text-2xl font-semibold tracking-tight">{payload.envelope.title}</h1>
        <p className="text-muted-foreground mt-1 text-sm">
          Sent by {payload.envelope.sender}. You are signing as {payload.recipient.name}.
        </p>
        <ul className="text-muted-foreground mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs">
          {payload.parties.map((party) => (
            <li key={party.schema_recipient_id}>
              {party.name}
              {party.role === null ? "" : ` (${party.role})`}
              {party.is_you ? " — you" : ""} · {party.state}
            </li>
          ))}
        </ul>
      </header>

      <section className="mt-8" aria-label="The agreement">
        <DocumentReview
          payload={payload}
          fields={schema.fields}
          values={state.values}
          ownFieldIds={payload.own_field_ids}
          onFocusField={focusField}
        />
      </section>

      <section className="mt-10" aria-label="What you need to complete">
        <h2 className="text-xl font-semibold tracking-tight">What you need to complete</h2>

        {ownFields.length === 0 ? (
          <p className="mt-4 text-sm">
            There is nothing for you to fill in on this agreement — only your signature is
            being asked for below.
          </p>
        ) : (
          <div className="mt-4 space-y-6">
            {ownFields.map((field) => (
              <div
                key={field.id}
                ref={(element) => {
                  if (element === null) {
                    entries.current.delete(field.id);
                  } else {
                    entries.current.set(field.id, element);
                  }
                }}
              >
                <FieldEntry
                  field={field}
                  value={state.values[field.id] ?? null}
                  recipientName={payload.recipient.name}
                  maxSignatureBytes={payload.limits.max_signature_image_bytes}
                  onChange={(value) => dispatch({ type: "set", fieldId: field.id, value })}
                />
              </div>
            ))}
          </div>
        )}

        {payload.service_supplied_field_ids.length === 0 ? null : (
          <p className="text-muted-foreground mt-4 text-xs">
            The date you signed is recorded by the service from its own clock when you agree,
            and is not something you or the sender can type.
          </p>
        )}

        {state.error === null ? null : (
          <p className="text-destructive mt-4 rounded-md border border-destructive/40 p-3 text-sm" role="alert">
            {state.error}
          </p>
        )}

        <div className="mt-6 flex flex-col gap-3 sm:flex-row sm:items-center">
          <button
            type="button"
            onClick={() => void save()}
            disabled={state.saving || state.dirty.length === 0}
            className="inline-flex min-h-11 items-center justify-center rounded-md border border-black/20 px-4 text-base font-medium disabled:opacity-50 dark:border-white/20"
          >
            {state.saving ? "Saving…" : "Save what I have filled in"}
          </button>

          <p className="text-muted-foreground text-sm" aria-live="polite">
            {state.dirty.length > 0
              ? `${state.dirty.length} unsaved ${state.dirty.length === 1 ? "change" : "changes"}.`
              : "Everything you have filled in is saved."}
          </p>
        </div>
      </section>

      <ConsentPanel
        payload={payload}
        reviewed={state.reviewed}
        ready={ready}
        blockedReason={blockedReason(state.dirty.length, missing.length)}
      />
    </div>
  );
}

function blockedReason(unsaved: number, missing: number): string | null {
  if (missing > 0) {
    return `Complete the ${missing} remaining required ${missing === 1 ? "field" : "fields"} above before signing.`;
  }

  if (unsaved > 0) {
    return "Save your changes before signing, so what you sign is what you filled in.";
  }

  return null;
}
