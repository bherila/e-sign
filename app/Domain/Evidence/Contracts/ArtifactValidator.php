<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Contracts;

use App\Domain\Evidence\Sealing\ValidationReport;

/**
 * Re-reads a sealed PDF and reports what it actually is.
 *
 * This exists because "the backend was asked for profile X" is not evidence
 * that the bytes reach profile X. An implementation inspects the produced
 * artifact and reports the signature's own properties, so publication can be
 * gated on the artifact rather than on the request.
 *
 * An in-process validator is a self-check, not an independent one. It shares
 * the signing library, so it cannot find a fault common to both directions.
 * The authoritative checks are the external validators run in CI against
 * synthetic artifacts (see scripts/validate-seal.sh).
 */
interface ArtifactValidator
{
    /**
     * Inspect sealed PDF bytes. Never throws for an invalid artifact: an
     * invalid artifact is a report with failures, which the caller must treat
     * as a hard stop.
     */
    public function validate(string $pdf): ValidationReport;
}
