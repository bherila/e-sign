<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Retention\Exceptions;

use RuntimeException;

/**
 * A deletion or erasure path met an envelope under legal hold and stopped.
 *
 * A refusal, never a skip that is reported as a success. "Legal holds override deletion"
 * (docs/HANDOFF.md section 12) only means anything if the operator learns that a hold is
 * what prevented the deletion; a sweep that silently passed over held envelopes and printed
 * a clean summary would be indistinguishable from one that deleted them.
 *
 * The bulk sweep never has to catch this, because it excludes held envelopes in its query
 * and reports the count it excluded. This is what a *targeted* command — erase a recipient,
 * purge one agreement — raises when it is aimed at a held envelope directly.
 */
final class LegalHoldActive extends RuntimeException
{
    public static function forEnvelope(string $publicId, ?string $reason): self
    {
        return new self(sprintf(
            'Envelope %s is under legal hold%s. Release the hold with `esign:hold:release` before '
            .'anything may be deleted or erased.',
            $publicId,
            $reason === null || $reason === '' ? '' : ' ('.$reason.')',
        ));
    }
}
