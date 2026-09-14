<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Anchoring;

use RuntimeException;

/**
 * Resolution produced an answer that contradicts the request it answers: a defect in this service.
 *
 * Deliberately not an {@see AnchorResolutionFailed}. That type means the field set is wrong, and
 * both HTTP surfaces report it as a 422 telling the caller to correct their input. A receipt that
 * contradicts its own request is nothing a caller sent. Every rule {@see ReceiptVerifier} checks
 * is a property of what the resolver has just computed. Answering 422 would tell a sender to edit a
 * document that is valid, and would bury this service's own defect in the validation path, where
 * nobody looks for one.
 *
 * So it is a server-side failure with its own code, and nothing is stored. The contradiction is
 * in the message, which goes to the log. The paths it names are members of the caller's own
 * document, never storage or engine detail.
 */
final class AnchorResolutionDefect extends RuntimeException
{
    /**
     * @param  list<array{path: string, reason: string}>  $contradictions
     */
    public static function receiptContradicts(string $fieldId, array $contradictions): self
    {
        return new self(
            'Anchor resolution produced a receipt for field "'.$fieldId.'" that contradicts the request it answers: '
                .implode('; ', array_map(
                    static fn (array $contradiction): string => $contradiction['path'].' '.$contradiction['reason'],
                    $contradictions,
                ))
                .'. This is a defect in the service, not in the document, and nothing was stored.',
        );
    }

    public function code(): string
    {
        return 'anchor_resolution_defect';
    }
}
