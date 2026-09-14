<?php

declare(strict_types=1);

namespace App\Domain\Signing\Envelopes;

use App\Domain\Preparation\Anchoring\AnchorDocumentUnavailable;
use App\Domain\Preparation\Anchoring\AnchorResolutionOutcome;
use App\Domain\Preparation\Anchoring\RevisionAnchorResolver;
use App\Domain\Preparation\Documents\Models\DocumentRevision;
use App\Domain\Signing\Contracts\AnchorResolution;
use App\Domain\Signing\Models\Envelope;
use Illuminate\Support\Facades\Log;

/**
 * The Signing module's half of anchor resolution: find the envelope's bytes, then delegate.
 *
 * It exists so `Preparation\Anchoring` never has to know what an envelope is. Everything it does
 * is envelope-shaped — which revision, which digest — and everything about anchors happens in
 * {@see RevisionAnchorResolver}.
 *
 * The digest check is not a formality. `envelopes.document_sha256` is copied at creation and every
 * attestation binds to it; if the revision a send is about to resolve against does not carry that
 * digest, the envelope points at bytes nobody agreed to, and resolving would place fields in a
 * document that is not the agreement. That is a refusal, not a repair — and, like a missing
 * revision, a data-integrity failure on the service side rather than something the sender can fix
 * by editing their field set, so it leaves as {@see AnchorDocumentUnavailable}.
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
            Log::error('Anchor resolution found no document revision for an envelope.', [
                'envelope_id' => $envelope->public_id,
            ]);

            throw AnchorDocumentUnavailable::readFailed((string) $envelope->public_id);
        }

        if (! hash_equals((string) $envelope->document_sha256, (string) $revision->sha256)) {
            Log::error('An envelope\'s document revision does not carry the digest the envelope was created against.', [
                'envelope_id' => $envelope->public_id,
                'document_revision_id' => $revision->public_id,
            ]);

            throw AnchorDocumentUnavailable::readFailed((string) $revision->public_id);
        }

        return $this->anchors->resolve($revision, $schema);
    }
}
