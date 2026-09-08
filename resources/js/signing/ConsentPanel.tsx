import { useId, useState } from "react";

import type { ReviewedState, SigningPayload } from "./types";

/**
 * The consent notice, the two statements a signer has to make, and the two forms that record
 * a decision.
 *
 * ## Two tick boxes, not one
 *
 * They are different statements and combining them would lose one of them. The first says the
 * person read the electronic-signature notice; the second says they intend to be bound by
 * this agreement. docs/HANDOFF.md section 8 asks for "explicit adoption/intent", and a single
 * "I agree" covering both would leave no record of which one somebody actually made.
 *
 * ## Real forms, not fetches
 *
 * Accepting and declining post and navigate. Their outcome is a page a person has to be able
 * to reload, print, and bookmark, and a decision that depends on JavaScript completing a
 * request is a decision that can be lost between the click and the record. The two hidden
 * fields carry `reviewed`, which is refreshed by every successful save — so what is submitted
 * is what the page was displaying, and if the server's has moved the acceptance is refused as
 * stale rather than bound to text nobody saw (docs/ARCHITECTURE.md invariant 2).
 *
 * ## When the notice and the version disagree
 *
 * `matches_recorded_version` is false when the text on disk is not the version this envelope
 * snapshotted. The panel says so, in place, rather than rendering current wording under an
 * older version's name — an attestation recording a version the signer was not shown is a
 * record of something that did not happen.
 */
export interface ConsentPanelProps {
  payload: SigningPayload;
  reviewed: ReviewedState;
  /** False while anything is unsaved or a required field is empty. */
  ready: boolean;
  /** What is still missing, for a message the signer can act on. */
  blockedReason: string | null;
}

export function ConsentPanel({ payload, reviewed, ready, blockedReason }: ConsentPanelProps) {
  const [consentAccepted, setConsentAccepted] = useState(false);
  const [intentConfirmed, setIntentConfirmed] = useState(false);
  const [declining, setDeclining] = useState(false);
  const consentId = useId();
  const intentId = useId();
  const reasonId = useId();

  const canSubmit = ready && consentAccepted && intentConfirmed;

  return (
    <section className="mt-10 border-t border-black/10 pt-8 dark:border-white/10">
      <h2 className="text-xl font-semibold tracking-tight">Before you sign</h2>

      {payload.consent.matches_recorded_version ? null : (
        <p className="text-destructive mt-4 rounded-md border border-destructive/40 p-3 text-sm" role="alert">
          The notice below is version {payload.consent.text_version}, and this agreement records
          version {payload.consent.recorded_version}. Ask the sender to confirm which applies
          before you sign.
        </p>
      )}

      {/*
        Rendered from resources/views/signing/consent.md by ConsentPolicy, which converts it
        with raw HTML escaped. The file is in the repository and is reviewed like code; this
        is not a place user input reaches.
      */}
      <div
        className="prose prose-sm dark:prose-invert mt-4 max-w-none"
        dangerouslySetInnerHTML={{ __html: payload.consent.html }}
      />

      <p className="text-muted-foreground mt-2 text-xs">
        Consent notice version {payload.consent.recorded_version}. This version is recorded with
        your signature.
      </p>

      <form method="POST" action={payload.urls.accept} className="mt-6 space-y-4">
        <input type="hidden" name="_token" value={payload.csrf_token} />
        <input type="hidden" name="consent_version" value={payload.consent.recorded_version} />
        <input
          type="hidden"
          name="reviewed_material_sha256"
          value={reviewed.material_values_sha256}
        />
        <input
          type="hidden"
          name="reviewed_envelope_version"
          value={String(reviewed.envelope_version)}
        />
        {payload.required_signature_field_ids.map((fieldId) => (
          <input key={fieldId} type="hidden" name="signature_field_ids[]" value={fieldId} />
        ))}

        <div className="flex items-start gap-3">
          <input
            id={consentId}
            name="consent_accepted"
            value="1"
            type="checkbox"
            checked={consentAccepted}
            onChange={(event) => setConsentAccepted(event.target.checked)}
            className="mt-1 h-6 w-6 shrink-0"
          />
          <label htmlFor={consentId} className="text-sm leading-6">
            I have read the notice above and agree to sign this agreement electronically.
          </label>
        </div>

        <div className="flex items-start gap-3">
          <input
            id={intentId}
            name="intent_confirmed"
            value="1"
            type="checkbox"
            checked={intentConfirmed}
            onChange={(event) => setIntentConfirmed(event.target.checked)}
            className="mt-1 h-6 w-6 shrink-0"
          />
          <label htmlFor={intentId} className="text-sm leading-6">
            I intend this to be my signature on <strong>{payload.envelope.title}</strong>, and I
            agree to be bound by it.
          </label>
        </div>

        {blockedReason === null ? null : (
          <p className="text-muted-foreground text-sm">{blockedReason}</p>
        )}

        <button
          type="submit"
          disabled={!canSubmit}
          className="inline-flex min-h-12 w-full items-center justify-center rounded-md bg-foreground px-6 text-base font-medium text-background disabled:opacity-50 sm:w-auto"
        >
          Sign this agreement
        </button>
      </form>

      <div className="mt-10 border-t border-black/10 pt-6 dark:border-white/10">
        {declining ? (
          <form method="POST" action={payload.urls.decline} className="space-y-4">
            <input type="hidden" name="_token" value={payload.csrf_token} />

            <label htmlFor={reasonId} className="block text-sm font-medium">
              Why are you declining? (optional)
            </label>
            <textarea
              id={reasonId}
              name="reason"
              rows={3}
              maxLength={500}
              className="block w-full rounded-md border border-black/20 bg-transparent p-3 text-base dark:border-white/20"
            />

            <div className="flex items-start gap-3">
              <input
                id={`${reasonId}-confirm`}
                name="decline_confirmed"
                value="1"
                type="checkbox"
                className="mt-1 h-6 w-6 shrink-0"
              />
              <label htmlFor={`${reasonId}-confirm`} className="text-sm leading-6">
                I understand declining stops this agreement for everybody, and that it cannot be
                reopened.
              </label>
            </div>

            <div className="flex flex-col gap-3 sm:flex-row">
              <button
                type="submit"
                className="inline-flex min-h-11 items-center justify-center rounded-md border border-destructive/50 px-4 text-base font-medium text-destructive"
              >
                Decline this agreement
              </button>
              <button
                type="button"
                onClick={() => setDeclining(false)}
                className="inline-flex min-h-11 items-center justify-center rounded-md border border-black/20 px-4 text-base dark:border-white/20"
              >
                Cancel
              </button>
            </div>
          </form>
        ) : (
          <button
            type="button"
            onClick={() => setDeclining(true)}
            className="inline-flex min-h-11 items-center rounded-md border border-black/20 px-4 text-base dark:border-white/20"
          >
            I do not want to sign this
          </button>
        )}
      </div>
    </section>
  );
}
