<?php

declare(strict_types=1);

namespace App\Domain\Signing\Exceptions;

use App\Domain\Preparation\Schema\ValidationError;

/**
 * The snapshot handed to the envelope factory does not describe a sendable agreement.
 *
 * Raised before any row is written. The snapshot is the boundary between this module and
 * whatever produced it — a template version, the native API, the Firma facade — and it is
 * the only place those callers get to be wrong, because after creation the envelope never
 * looks at its source again.
 */
final class InvalidEnvelopeSnapshot extends SigningException
{
    /**
     * @param  list<ValidationError>  $problems  The structured errors behind this refusal, when
     *                                           there are any. A snapshot whose field schema does
     *                                           not import carries the importer's own list rather
     *                                           than flattening it into one sentence: codes are API
     *                                           surface and a caller branches on them, so a client
     *                                           told only "invalid_field_schema" has to guess which
     *                                           of a dozen rules it broke.
     */
    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly array $problems = [],
    ) {
        parent::__construct($message);
    }

    public static function digestMismatch(string $declared, string $actual): self
    {
        return new self(
            'The snapshot declares document digest '.$declared.' but the referenced revision holds '.$actual.'.',
            'document_digest_mismatch',
        );
    }

    public static function parallelWithMultipleStages(int $stages): self
    {
        return new self(
            'Parallel signing was requested, but the field schema declares '.$stages.' signing stages. '
            .'Either the order or the mode is wrong; neither is safe to ignore.',
            'parallel_with_multiple_stages',
        );
    }

    public static function unknownDocumentRevision(int $revisionId): self
    {
        return new self(
            'Document revision '.$revisionId.' does not exist.',
            'unknown_document_revision',
        );
    }

    /**
     * Cross-workspace isolation, enforced before the row exists.
     *
     * A snapshot naming a revision from another workspace is either a bug or a probe. Either
     * way an envelope must not come into being that binds one tenant's agreement into
     * another tenant's workspace.
     */
    public static function documentNotInWorkspace(int $revisionId): self
    {
        return new self(
            'Document revision '.$revisionId.' does not belong to this workspace.',
            'document_not_in_workspace',
        );
    }

    public static function missingProperty(string $property): self
    {
        return new self('The envelope snapshot is missing "'.$property.'".', 'missing_property');
    }

    public static function invalidProperty(string $property, string $detail): self
    {
        return new self('The envelope snapshot property "'.$property.'" is invalid: '.$detail, 'invalid_property');
    }

    public function code(): string
    {
        return $this->reason;
    }
}
