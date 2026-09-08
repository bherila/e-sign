<?php

declare(strict_types=1);

namespace App\Http\Controllers\Signing;

use App\Domain\Signing\Envelopes\AcceptanceRequest;
use App\Domain\Signing\Envelopes\EnvelopeStateMachine;
use App\Domain\Signing\Exceptions\ConsentMismatch;
use App\Domain\Signing\Exceptions\IllegalTransition;
use App\Domain\Signing\Exceptions\RecipientNotEligible;
use App\Domain\Signing\Exceptions\RequiredFieldsMissing;
use App\Domain\Signing\Exceptions\StaleEnvelope;
use App\Domain\Signing\Exceptions\StaleReview;
use App\Domain\Signing\Sessions\ClientFingerprint;
use App\Domain\Signing\Sessions\GuestSigningContext;
use App\Domain\Signing\Sessions\KeyedDigest;
use App\Http\Controllers\Controller;
use App\Http\Requests\Signing\AcceptAgreementRequest;
use App\Http\Requests\Signing\DeclineAgreementRequest;
use Symfony\Component\HttpFoundation\Response;

/**
 * The two decisions a signer can make, and the only two endpoints that record one.
 *
 * Both are ordinary form posts that redirect, not fetches. Their outcome is a page somebody
 * has to be able to bookmark, print, and reload, and a decision that depends on JavaScript
 * completing a request is a decision that can be lost between the click and the record.
 *
 * ## What this controller does and does not decide
 *
 * It decides nothing. Every rule about who may accept, whether the review is current,
 * whether consent matches, and what an acceptance does to the envelope lives in
 * {@see EnvelopeStateMachine} — AGENTS.md: "One state machine … never put signing rules in a
 * compatibility controller", and a guest UI is no more entitled to its own copy than a
 * compatibility facade is. What is here is translation: a validated form becomes an
 * {@see AcceptanceRequest}, and each refusal becomes the page that refusal deserves.
 *
 * ## The evidence this adds
 *
 * `sessionRef` is the signing session's ULID, which makes it the idempotency key: a
 * double-submitted form, a browser retry, and a user pressing back and re-submitting all
 * produce one attestation (docs/ARCHITECTURE.md invariant 7), and the second one is reported
 * as a replay rather than refused. The signer sees the same confirmation either way, which
 * is the honest answer — they did sign, once.
 *
 * `clientEvidence` is minimized on the way in by `ClientEvidence`'s allowlist and is
 * corroboration only. docs/HANDOFF.md section 8 is explicit that an IP address and a user
 * agent are not identity proof, and nothing here or downstream treats them as any.
 *
 * ## Stale review
 *
 * `StaleReview` is a 409 with a page that explains the agreement changed and offers to
 * reload it. Not a redirect to the top of the flow, and never an automatic retry: the whole
 * point of invariant 2 is that a person re-reads what actually changed before agreeing to
 * it, so the page asks them to.
 */
class SigningAssentController extends Controller
{
    public function __construct(
        private readonly EnvelopeStateMachine $machine,
        private readonly KeyedDigest $digest,
    ) {}

    public function accept(AcceptAgreementRequest $request, GuestSigningContext $context): Response
    {
        // No `mayAct()` guard here, unlike `decline()`, and the asymmetry is invariant 7.
        // A retry of an acceptance necessarily arrives when the recipient is already
        // `signed`, so refusing on eligibility before calling the state machine would turn
        // every double-submitted form into an error page for somebody who did sign. The
        // state machine checks for a replay *first*, precisely so this path can hand it
        // every attempt and let it decide; an ineligible caller with no prior attestation
        // still comes back as RecipientNotEligible below.
        $fingerprint = ClientFingerprint::of($request, $this->digest);

        $acceptance = new AcceptanceRequest(
            consentPolicyVersion: (string) $request->validated('consent_version'),
            sessionRef: $context->sessionRef(),
            reviewedMaterialSha256: (string) $request->validated('reviewed_material_sha256'),
            reviewedEnvelopeVersion: (int) $request->validated('reviewed_envelope_version'),
            verificationMethod: $context->session->verification_method->attested(),
            clientEvidence: $fingerprint->asClientEvidence($request, 'guest_signing_page'),
        );

        try {
            $this->machine->accept($context->recipient, $acceptance);
        } catch (StaleReview|ConsentMismatch $stale) {
            return $this->staleReview($context, $stale->code());
        } catch (RequiredFieldsMissing $missing) {
            return back()->withErrors(['signature_field_ids' => $missing->getMessage()])->setStatusCode(303);
        } catch (RecipientNotEligible|IllegalTransition|StaleEnvelope) {
            return $this->notOpen($context);
        }

        // Straight back to the session page, which renders the confirmation because the
        // recipient is now `signed`. One URL for the whole session means a reload after
        // signing shows the confirmation instead of a 403 or a resubmission prompt.
        return redirect()->route('signing.session.show', ['envelope' => $context->envelope->public_id], 303);
    }

    public function decline(DeclineAgreementRequest $request, GuestSigningContext $context): Response
    {
        if (! $context->mayAct()) {
            return $this->notOpen($context);
        }

        try {
            $this->machine->decline($context->recipient, $request->reason());
        } catch (RecipientNotEligible|IllegalTransition|StaleEnvelope) {
            return $this->notOpen($context);
        }

        return redirect()->route('signing.session.show', ['envelope' => $context->envelope->public_id], 303);
    }

    private function staleReview(GuestSigningContext $context, string $code): Response
    {
        return response()->view('signing.stale', [
            'title' => $context->envelope->title,
            'reason' => $code,
            'reloadUrl' => route('signing.session.show', ['envelope' => $context->envelope->public_id]),
        ], 409);
    }

    private function notOpen(GuestSigningContext $context): Response
    {
        return response()->view('signing.unavailable', [
            'reason' => 'envelope_'.$context->envelope->state->value,
            'title' => $context->envelope->title,
        ], 409);
    }
}
