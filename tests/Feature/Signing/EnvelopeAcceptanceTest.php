<?php

declare(strict_types=1);

namespace Tests\Feature\Signing;

use App\Domain\Signing\Envelopes\AcceptanceRequest;
use App\Domain\Signing\Envelopes\AttestationDigest;
use App\Domain\Signing\Envelopes\VerificationMethod;
use App\Domain\Signing\Exceptions\ConsentMismatch;
use App\Domain\Signing\Exceptions\RequiredFieldsMissing;
use App\Domain\Signing\Exceptions\StaleReview;
use App\Domain\Signing\Models\RecipientAttestation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\SigningScenario;
use Tests\TestCase;

/**
 * The attestation is the acceptance: what it binds, what it refuses, and why a retry is not
 * a second signature.
 */
class EnvelopeAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_attestation_binds_the_document_schema_material_and_consent(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->sent();
        $buyer = $scenario->recipient($envelope, 'buyer');

        $result = $scenario->signAs($envelope, $buyer, 'session-buyer');
        $attestation = $result->attestation;

        $this->assertFalse($result->replayed);
        $this->assertSame($envelope->document_sha256, $attestation->document_sha256);
        $this->assertSame($envelope->field_schema_sha256, $attestation->field_schema_sha256);
        $this->assertSame($envelope->consent_policy_version, $attestation->consent_policy_version);
        $this->assertSame('session-buyer', $attestation->session_ref);
        $this->assertSame(VerificationMethod::EmailLink, $attestation->verification_method);
        $this->assertNull($attestation->prev_attestation_sha256);
        $this->assertSame(
            $scenario->machine()->materialValuesDigest($envelope->refresh()),
            $attestation->material_values_sha256,
        );
    }

    public function test_client_evidence_is_minimized_to_an_allowlist(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->sent();
        $buyer = $scenario->recipient($envelope, 'buyer');
        $scenario->completeRequiredFieldsFor($envelope, $buyer);

        $request = new AcceptanceRequest(
            consentPolicyVersion: $envelope->refresh()->consent_policy_version,
            sessionRef: 'session-buyer',
            reviewedMaterialSha256: $scenario->machine()->materialValuesDigest($envelope),
            reviewedEnvelopeVersion: $envelope->version,
            clientEvidence: [
                'ip' => '198.51.100.7',
                'user_agent' => 'SyntheticBrowser/1.0',
                'cookie' => 'session=secret',
                'canvas_fingerprint' => 'abcdef',
                'nested' => ['not' => 'allowed'],
            ],
        );

        $attestation = $scenario->machine()->accept($buyer->refresh(), $request)->attestation;

        // Read back from the row, not from the model that wrote it, and with assertEquals:
        // MySQL does not preserve `JSON` object key order.
        $stored = RecipientAttestation::query()->findOrFail($attestation->getKey());

        $this->assertEquals([
            'ip' => '198.51.100.7',
            'user_agent' => 'SyntheticBrowser/1.0',
        ], $stored->client_evidence);
    }

    public function test_the_hash_chain_links_each_acceptance_to_the_one_before_it(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->sent();

        $first = $scenario->signAs($envelope, $scenario->recipient($envelope, 'buyer'), 'session-buyer');
        $second = $scenario->signAs(
            $envelope->refresh(),
            $scenario->recipient($envelope, 'seller'),
            'session-seller',
        );

        $this->assertNull($first->attestation->prev_attestation_sha256);
        $this->assertSame(
            $first->attestation->attestation_sha256,
            $second->attestation->prev_attestation_sha256,
        );
        $this->assertNotSame(
            $first->attestation->attestation_sha256,
            $second->attestation->attestation_sha256,
        );
    }

    public function test_an_attestation_digest_can_be_recomputed_from_its_own_row(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->sent();
        $written = $scenario->signAs($envelope, $scenario->recipient($envelope, 'buyer'))->attestation;

        // From the row, which is the claim being made. It matters that this survives the
        // storage round trip: a MySQL `JSON` column does not preserve object key order, so
        // the digest has to be independent of how `client_evidence` comes back out — which
        // is why AttestationDigest re-minimizes it rather than hashing it as given.
        $attestation = RecipientAttestation::query()->findOrFail($written->getKey());

        $recomputed = AttestationDigest::compute(
            $envelope->refresh()->public_id,
            $attestation->recipient->public_id,
            $attestation->document_sha256,
            $attestation->field_schema_sha256,
            $attestation->material_values_sha256,
            $attestation->consent_policy_version,
            $attestation->session_ref,
            $attestation->accepted_at->utc()->format(AttestationDigest::TIME_FORMAT),
            $attestation->verification_method,
            $attestation->client_evidence,
            $attestation->prev_attestation_sha256,
        );

        $this->assertSame($attestation->attestation_sha256, $recomputed);
    }

    public function test_attestations_refuse_updates_and_deletes(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->sent();
        $attestation = $scenario->signAs($envelope, $scenario->recipient($envelope, 'buyer'))->attestation;

        try {
            $attestation->update(['consent_policy_version' => 'consent-1999-01']);
            $this->fail('An acceptance cannot be edited.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        try {
            $attestation->delete();
            $this->fail('An acceptance cannot be deleted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        $this->assertSame(
            'consent-2026-01',
            RecipientAttestation::query()->sole()->consent_policy_version,
        );
    }

    public function test_a_repeated_acceptance_in_the_same_session_returns_the_original(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->sent();
        $buyer = $scenario->recipient($envelope, 'buyer');
        $scenario->completeRequiredFieldsFor($envelope, $buyer);
        $request = $scenario->acceptanceRequest($envelope, 'session-retry');

        $first = $scenario->machine()->accept($buyer->refresh(), $request);
        $versionAfterFirst = $envelope->refresh()->version;

        // The same request object, exactly as an at-least-once transport would redeliver it.
        $second = $scenario->machine()->accept($buyer->refresh(), $request);

        $this->assertFalse($first->replayed);
        $this->assertTrue($second->replayed);
        $this->assertSame($first->attestation->getKey(), $second->attestation->getKey());
        $this->assertSame([], $second->eventNames());
        $this->assertSame(1, RecipientAttestation::query()->count());
        $this->assertSame($versionAfterFirst, $envelope->refresh()->version);
    }

    public function test_a_reused_session_quoting_different_material_is_a_stale_review(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->sent();
        $buyer = $scenario->recipient($envelope, 'buyer');
        $scenario->completeRequiredFieldsFor($envelope, $buyer);
        $request = $scenario->acceptanceRequest($envelope, 'session-retry');
        $scenario->machine()->accept($buyer->refresh(), $request);

        $this->expectException(StaleReview::class);

        $scenario->machine()->accept($buyer->refresh(), new AcceptanceRequest(
            consentPolicyVersion: $request->consentPolicyVersion,
            sessionRef: 'session-retry',
            reviewedMaterialSha256: hash('sha256', 'something else entirely'),
            reviewedEnvelopeVersion: $envelope->refresh()->version,
        ));
    }

    /** Invariant 2: a sender edit between review and assent invalidates the review. */
    public function test_a_sender_edit_to_a_material_field_makes_a_review_stale(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->sent();
        $buyer = $scenario->recipient($envelope, 'buyer');
        $scenario->completeRequiredFieldsFor($envelope, $buyer);

        $reviewed = $scenario->acceptanceRequest($envelope, 'session-buyer');

        // The sender corrects the effective date after the buyer read the document.
        $scenario->machine()->setSenderValues($envelope->refresh(), ['agreement_effective_date' => '2026-03-01']);

        try {
            $scenario->machine()->accept($buyer->refresh(), $reviewed);
            $this->fail('Assent must not be recorded against text the signer never saw.');
        } catch (StaleReview $e) {
            $this->assertSame('stale_review', $e->code());
            $this->assertSame('envelope_version_moved', $e->reason);
        }

        // Re-reviewing succeeds, and binds the corrected material.
        $result = $scenario->machine()->accept(
            $buyer->refresh(),
            $scenario->acceptanceRequest($envelope, 'session-buyer'),
        );

        $this->assertSame(
            $scenario->machine()->materialValuesDigest($envelope->refresh()),
            $result->attestation->material_values_sha256,
        );
    }

    public function test_a_stale_material_digest_is_refused_even_at_the_current_version(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->sent();
        $buyer = $scenario->recipient($envelope, 'buyer');
        $scenario->completeRequiredFieldsFor($envelope, $buyer);
        $envelope->refresh();

        try {
            $scenario->machine()->accept($buyer->refresh(), new AcceptanceRequest(
                consentPolicyVersion: $envelope->consent_policy_version,
                sessionRef: 'session-buyer',
                reviewedMaterialSha256: hash('sha256', 'a document that was never shown'),
                reviewedEnvelopeVersion: $envelope->version,
            ));
            $this->fail('The material digest is checked on its own, not only via the version.');
        } catch (StaleReview $e) {
            $this->assertSame('material_values_moved', $e->reason);
        }
    }

    public function test_consent_cannot_be_bypassed_by_presenting_another_version(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->sent();
        $buyer = $scenario->recipient($envelope, 'buyer');
        $scenario->completeRequiredFieldsFor($envelope, $buyer);

        try {
            $scenario->machine()->accept(
                $buyer->refresh(),
                $scenario->acceptanceRequest($envelope, 'session-buyer', consentPolicyVersion: 'consent-1999-01'),
            );
            $this->fail('A client flag about consent carries no authority.');
        } catch (ConsentMismatch $e) {
            $this->assertSame('consent-1999-01', $e->presented);
            $this->assertSame('consent-2026-01', $e->expected);
        }

        $this->assertSame(0, RecipientAttestation::query()->count());
    }

    public function test_required_fields_cannot_be_bypassed_by_accepting_without_them(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->sent();
        $buyer = $scenario->recipient($envelope, 'buyer');

        // No submitValues call at all: the client simply claims it is done.
        try {
            $scenario->machine()->accept($buyer, $scenario->acceptanceRequest($envelope, 'session-buyer'));
            $this->fail('Required fields are validated on the server at acceptance.');
        } catch (RequiredFieldsMissing $e) {
            $this->assertSame('buyer', $e->recipientId);
            $this->assertEqualsCanonicalizing(['buyer_ack', 'buyer_signature'], $e->fieldIds);
        }

        $this->assertSame(0, RecipientAttestation::query()->count());
    }

    public function test_a_service_supplied_required_field_is_not_demanded_from_the_recipient(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->sent();
        $scenario->signAs($envelope, $scenario->recipient($envelope, 'buyer'), 'session-buyer');

        // seller_signed_at is required, read-only, and service-supplied; the seller can still sign.
        $result = $scenario->signAs(
            $envelope->refresh(),
            $scenario->recipient($envelope, 'seller'),
            'session-seller',
        );

        $this->assertNotNull($result->attestation->accepted_at);
    }
}
