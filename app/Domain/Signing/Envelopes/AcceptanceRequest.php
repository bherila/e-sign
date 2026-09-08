<?php

declare(strict_types=1);

namespace App\Domain\Signing\Envelopes;

use App\Domain\Signing\Fields\ClientEvidence;
use InvalidArgumentException;

/**
 * Everything a recipient has to state to give assent.
 *
 * The two "reviewed" properties are not bookkeeping — they are docs/ARCHITECTURE.md
 * invariant 2 made unavoidable. An acceptance binds to what the person actually saw, so the
 * session says what it displayed and the state machine refuses if that is no longer what the
 * envelope holds. Passing the *current* values instead of the reviewed ones would satisfy
 * the check and defeat its purpose, which is why they arrive from the caller rather than
 * being read inside.
 *
 * `sessionRef` identifies the recipient session the assent was given in, and it is also the
 * idempotency key: repeating an acceptance in the same session with the same material
 * digest returns the acceptance that already exists rather than recording a second one
 * (docs/ARCHITECTURE.md invariant 7). A retried request, a double-submitted form, and a
 * worker redelivery therefore all produce one logical acceptance.
 *
 * `clientEvidence` is minimized on the way in ({@see ClientEvidence}) — an allowlist, not a
 * scrubber — and is corroboration, never identity proof (docs/HANDOFF.md section 8).
 */
final readonly class AcceptanceRequest
{
    /**
     * Column widths, enforced before the row is attempted.
     *
     * A session reference too long for its column is a truncation on a permissive engine and
     * an opaque driver error on a strict one — and a *truncated* session reference is worse
     * than either, because it is the idempotency key: two different sessions sharing a
     * prefix would collide into one logical acceptance.
     */
    public const MAX_SESSION_REF_LENGTH = 191;

    public const MAX_CONSENT_POLICY_VERSION_LENGTH = 64;

    /** @var array<string, string> */
    public array $clientEvidence;

    /**
     * @param  array<string, mixed>  $clientEvidence  Minimized here; anything unrecognised is dropped.
     */
    public function __construct(
        public string $consentPolicyVersion,
        public string $sessionRef,
        public string $reviewedMaterialSha256,
        public int $reviewedEnvelopeVersion,
        public VerificationMethod $verificationMethod = VerificationMethod::EmailLink,
        array $clientEvidence = [],
    ) {
        if (trim($sessionRef) === '' || mb_strlen($sessionRef) > self::MAX_SESSION_REF_LENGTH) {
            throw new InvalidArgumentException(
                'A session reference must be non-empty and at most '
                .self::MAX_SESSION_REF_LENGTH.' characters; it is the idempotency key.',
            );
        }

        if (
            trim($consentPolicyVersion) === ''
            || mb_strlen($consentPolicyVersion) > self::MAX_CONSENT_POLICY_VERSION_LENGTH
        ) {
            throw new InvalidArgumentException(
                'A consent policy version must be non-empty and at most '
                .self::MAX_CONSENT_POLICY_VERSION_LENGTH.' characters.',
            );
        }

        $this->clientEvidence = ClientEvidence::minimize($clientEvidence);
    }
}
