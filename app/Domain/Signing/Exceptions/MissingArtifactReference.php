<?php

declare(strict_types=1);

namespace App\Domain\Signing\Exceptions;

/**
 * Completion was claimed without a reference to the artifact that makes it true.
 *
 * docs/ARCHITECTURE.md invariant 5: a completed envelope has a durable, validated final PDF
 * and a complete evidence reference — "not merely a set of signature images or a status
 * row". The state machine cannot verify that the bytes exist, which is the finalizer's job,
 * but it can refuse to write `completed` with nothing in the field that is supposed to point
 * at them.
 */
final class MissingArtifactReference extends SigningException
{
    public function __construct()
    {
        parent::__construct(
            'An envelope cannot be marked completed without a reference to its published final artifact.',
        );
    }

    public function code(): string
    {
        return 'missing_artifact_reference';
    }
}
