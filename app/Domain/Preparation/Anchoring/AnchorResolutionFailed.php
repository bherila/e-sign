<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Anchoring;

use App\Domain\Preparation\Schema\ValidationError;
use App\Domain\Preparation\Schema\ValidationResult;
use RuntimeException;

/**
 * One or more anchors could not be turned into a rectangle, with every reason at once.
 *
 * Reporting all of them follows the same rule as the field-schema importer and the send gate: a
 * sender fixing a document should see the whole list rather than discover the next problem after
 * fixing the previous one.
 *
 * There is deliberately no "place it somewhere sensible and carry on" branch anywhere behind
 * this exception. A field placed at a fallback position is a field nobody agreed to sign there,
 * and a field silently dropped is a signature nobody was asked for; both are worse than a
 * refusal a sender can act on while it is still cheap to act (docs/HANDOFF.md section 7).
 */
final class AnchorResolutionFailed extends RuntimeException
{
    /**
     * @param  list<AnchorResolutionProblem>  $problems
     */
    public function __construct(public readonly array $problems)
    {
        parent::__construct(
            count($problems) === 1
                ? $problems[0]->message
                : count($problems).' fields could not be anchored: '.implode(' ', array_map(
                    static fn (AnchorResolutionProblem $problem): string => $problem->message,
                    $problems,
                )),
        );
    }

    /** The publish-path shape: JSON Pointers into the document, one per problem. */
    public function toValidationResult(): ValidationResult
    {
        return new ValidationResult(array_map(
            static fn (AnchorResolutionProblem $problem): ValidationError => $problem->toValidationError(),
            $this->problems,
        ));
    }

    /**
     * The send-path shape: one entry per problem in `SendPreconditionsFailed::$problems`.
     *
     * @return list<array{code: string, message: string, field: string, recipient: string, anchor_text: string, found: string}>
     */
    public function toSendProblems(): array
    {
        return array_map(
            static fn (AnchorResolutionProblem $problem): array => $problem->toSendProblem(),
            $this->problems,
        );
    }

    /**
     * @return list<string>
     */
    public function fieldIds(): array
    {
        return array_map(static fn (AnchorResolutionProblem $problem): string => $problem->fieldId, $this->problems);
    }
}
