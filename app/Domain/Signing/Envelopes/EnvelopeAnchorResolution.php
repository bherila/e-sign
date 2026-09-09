<?php

declare(strict_types=1);

namespace App\Domain\Signing\Envelopes;

use App\Domain\Preparation\Anchoring\AnchorResolutionFailed;
use App\Domain\Preparation\Anchoring\AnchorResolutionOutcome;
use App\Domain\Preparation\Anchoring\AnchorResolutionProblem;
use App\Domain\Preparation\Anchoring\RevisionAnchorResolver;
use App\Domain\Preparation\Documents\Models\DocumentRevision;
use App\Domain\Preparation\Schema\ValidationCode;
use App\Domain\Signing\Contracts\AnchorResolution;
use App\Domain\Signing\Models\Envelope;

/**
 * The Signing module's half of anchor resolution: find the envelope's bytes, then delegate.
 *
 * It exists so `Preparation\Anchoring` never has to know what an envelope is. Everything it does
 * is envelope-shaped — which revision, which digest, what to say when the revision is gone — and
 * everything about anchors happens in {@see RevisionAnchorResolver}.
 *
 * The digest check is not a formality. `envelopes.document_sha256` is copied at creation and
 * checked against the revision by {@see EnvelopeFactory}, and every attestation binds to it; if
 * the revision a send is about to resolve against does not carry that digest, the envelope is
 * pointing at bytes nobody agreed to and resolving would place fields in a document that is not
 * the agreement. That is a refusal, not a repair.
 */
final readonly class EnvelopeAnchorResolution implements AnchorResolution
{
    public function __construct(private RevisionAnchorResolver $anchors) {}

    public function forEnvelope(Envelope $envelope): AnchorResolutionOutcome
    {
        $schema = $envelope->fieldSchema();

        if ($schema->anchoredFields() === []) {
            return AnchorResolutionOutcome::unchanged($schema);
        }

        $revision = $envelope->documentRevision()->with('document')->first();

        if (! $revision instanceof DocumentRevision) {
            throw new AnchorResolutionFailed($this->documentProblems(
                $envelope,
                'the envelope\'s document revision is missing',
            ));
        }

        if (! hash_equals((string) $envelope->document_sha256, (string) $revision->sha256)) {
            throw new AnchorResolutionFailed($this->documentProblems(
                $envelope,
                'the envelope was created against document '.$envelope->document_sha256
                    .' and its revision now reads '.$revision->sha256,
            ));
        }

        return $this->anchors->resolve($revision, $schema);
    }

    /**
     * One problem per anchored field, so the caller's error list has the same shape whatever
     * went wrong: the sender sees which fields cannot be placed, not a bare document error.
     *
     * @return list<AnchorResolutionProblem>
     */
    private function documentProblems(Envelope $envelope, string $because): array
    {
        $problems = [];

        foreach ($envelope->fieldSchema()->fields as $index => $field) {
            if ($field->anchor === null) {
                continue;
            }

            $problems[] = new AnchorResolutionProblem(
                $index,
                $field->id,
                $field->recipientId,
                $field->anchor->text,
                ValidationCode::AnchorTextUnreadable,
                $because,
                'Field "'.$field->id.'" is anchored to "'.$field->anchor->text.'" and cannot be resolved because '
                    .$because.'.',
            );
        }

        return $problems;
    }
}
