<?php

declare(strict_types=1);

namespace App\Http\Controllers\Signing;

use App\Domain\Preparation\Documents\Models\Document;
use App\Domain\Signing\Envelopes\RecipientState;
use App\Domain\Signing\Models\RecipientAttestation;
use App\Domain\Signing\Sessions\GuestSigningContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\Signing\SigningPagePayload;
use Illuminate\Contracts\View\View;

/**
 * The page a guest actually signs on — and, once they have, the page that tells them so.
 *
 * One GET rather than a signing route plus a separate confirmation route, because the
 * alternative is worse in both directions. A dedicated confirmation URL either outlives the
 * session (and becomes a second thing to authorize) or dies with it (and turns a refresh
 * after signing into a 403). Rendering from the recipient's own state means a reload after
 * signing shows the confirmation, a reload after declining shows the decline, and neither
 * needs a flash message to survive.
 *
 * ## The four states
 *
 * | Recipient / envelope | What is rendered |
 * |---|---|
 * | active, envelope open | the React signing island |
 * | signed | confirmation, with the server acceptance time from the attestation |
 * | declined | the decline notice |
 * | anything else | "no longer open", the same page a cancelled or expired envelope gets |
 *
 * The confirmation reads its timestamp from `recipient_attestations.accepted_at` rather than
 * from `envelope_recipients.signed_at`. Both are written in the same transaction and would
 * agree, but the attestation *is* the acceptance (docs/signing/state-machine.md) and the
 * recipient row is an index into it, so the page quotes the record rather than the index.
 *
 * The document itself is never inlined here. It is streamed by
 * {@see SigningDocumentController} through a signing-scoped route, so the bytes are
 * authorized by the same session as the page.
 */
class SigningSessionController extends Controller
{
    public function show(GuestSigningContext $context, SigningPagePayload $payload): View
    {
        if ($context->recipient->state === RecipientState::Signed) {
            return $this->confirmation($context);
        }

        if ($context->recipient->state === RecipientState::Declined) {
            return view('signing.declined', [
                'title' => $context->envelope->title,
                'reason' => $context->recipient->decline_reason,
                'declinedAt' => $context->recipient->declined_at,
                'returnUrl' => $context->session->return_url,
            ]);
        }

        if (! $context->mayAct()) {
            return view('signing.unavailable', [
                'reason' => 'envelope_'.$context->envelope->state->value,
                'title' => $context->envelope->title,
            ]);
        }

        $document = $this->document($context);

        return view('signing.session', [
            'signing' => $payload->build($context, $document, csrf_token()),
            'title' => $context->envelope->title,
        ]);
    }

    private function confirmation(GuestSigningContext $context): View
    {
        $attestation = RecipientAttestation::query()
            ->where('recipient_id', $context->recipient->getKey())
            ->orderByDesc('id')
            ->first();

        return view('signing.complete', [
            'title' => $context->envelope->title,
            'recipientName' => $context->recipient->name,
            // The server's clock, in UTC, as recorded. Never a client timestamp, and never
            // reformatted into a zone the record does not claim.
            'acceptedAt' => $attestation?->accepted_at,
            'attestationId' => $attestation?->public_id,
            'everyoneSigned' => $context->envelope->recipients()
                ->where('state', '!=', RecipientState::Signed->value)
                ->doesntExist(),
            'returnUrl' => $context->session->return_url,
        ]);
    }

    /**
     * The document behind the envelope's review revision.
     *
     * A missing one is a corrupted row rather than a request problem: an envelope always
     * references the revision it snapshotted, and the foreign key is RESTRICT. Fail closed
     * rather than render a signing page with no document on it.
     */
    private function document(GuestSigningContext $context): Document
    {
        $revision = $context->envelope->documentRevision()->with('document')->first();
        $document = $revision?->document;

        if (! $document instanceof Document) {
            abort(404);
        }

        return $document;
    }
}
