<?php

declare(strict_types=1);

namespace App\Domain\Signing\Envelopes;

use App\Domain\Signing\Fields\CanonicalValue;

/**
 * The digest that identifies one acceptance and links it to the one before it.
 *
 * Every fact that makes the acceptance what it is goes in, in a fixed order, under a
 * versioned encoding name — the same rule docs/HANDOFF.md section 8 sets for the wider
 * evidence encoding. `prev` is the previous acceptance's digest on the same envelope, or
 * null for the first, so removing or re-ordering an acceptance breaks every digest after it.
 *
 * What this proves and what it does not: the chain shows that the sequence of acceptances
 * has not been edited *by something that did not also recompute the chain*. It lives in a
 * database the application can write to, so it is not an independent witness, and
 * docs/HANDOFF.md section 8 requires saying so rather than implying tamper-proof storage.
 * Off-host checkpoints and signer copies are what make it one; they are a separate
 * deliverable.
 *
 * `accepted_at` is formatted to whole seconds in UTC, matching what the column stores, so
 * the digest of a row can be recomputed from the row.
 */
final class AttestationDigest
{
    public const ENCODING = 'esign.attestation.v1';

    /** The exact spelling of the acceptance instant that goes into the digest. */
    public const TIME_FORMAT = 'Y-m-d\TH:i:s\Z';

    /**
     * @param  array<string, mixed>  $clientEvidence
     */
    public static function compute(
        string $envelopePublicId,
        string $recipientPublicId,
        string $documentSha256,
        string $fieldSchemaSha256,
        string $materialValuesSha256,
        string $consentPolicyVersion,
        string $sessionRef,
        string $acceptedAt,
        VerificationMethod $verificationMethod,
        array $clientEvidence,
        ?string $previousDigest,
    ): string {
        return hash('sha256', CanonicalValue::encode([
            'encoding' => self::ENCODING,
            'envelope' => $envelopePublicId,
            'recipient' => $recipientPublicId,
            'document_sha256' => $documentSha256,
            'field_schema_sha256' => $fieldSchemaSha256,
            'material_values_sha256' => $materialValuesSha256,
            'consent_policy_version' => $consentPolicyVersion,
            'session_ref' => $sessionRef,
            'accepted_at' => $acceptedAt,
            'verification_method' => $verificationMethod->value,
            'client_evidence' => $clientEvidence,
            'prev' => $previousDigest,
        ]));
    }
}
