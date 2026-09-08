<?php

declare(strict_types=1);

namespace App\Domain\Signing\Capture;

/**
 * The consent notice as one page will display it, and an honest statement of whether it is
 * the notice the envelope's recorded version names.
 *
 * The two versions are separate fields on purpose. `recordedVersion` is the immutable
 * snapshot on the envelope — the string that goes onto the attestation and the one the
 * state machine checks — and `textVersion` is what the file currently on disk says it is.
 * They agree in every ordinary deployment, and when they do not, `matchesRecordedVersion` is
 * false and the page says so.
 *
 * Rendering the current text under an older version's name would be the quiet dishonesty
 * worth engineering against: an attestation that says "consent-2026-01" while the signer was
 * shown 2027's wording is a record of something that did not happen.
 */
final readonly class ConsentText
{
    public function __construct(
        /** The version snapshotted on the envelope; what the attestation will record. */
        public string $recordedVersion,
        /** The version the deployment says the text on disk is. */
        public string $textVersion,
        /** Rendered HTML, from a repository file rather than from anything user-supplied. */
        public string $html,
    ) {}

    public function matchesRecordedVersion(): bool
    {
        return $this->recordedVersion === $this->textVersion;
    }
}
