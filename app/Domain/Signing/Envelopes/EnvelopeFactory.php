<?php

declare(strict_types=1);

namespace App\Domain\Signing\Envelopes;

use App\Domain\Identity\Models\Workspace;
use App\Domain\Preparation\Documents\Models\DocumentRevision;
use App\Domain\Preparation\Schema\Recipient as SchemaRecipient;
use App\Domain\Signing\Contracts\EnvelopeEventSink;
use App\Domain\Signing\Exceptions\InvalidEnvelopeSnapshot;
use App\Domain\Signing\Models\Envelope;
use App\Domain\Signing\Models\EnvelopeRecipient;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Builds an envelope by copying a snapshot, and never by referencing its source.
 *
 * This is the one place a template, a document, or a consent policy is read on an envelope's
 * behalf. Afterwards the envelope holds its own copy of every one of them, so a template
 * edited tomorrow cannot change an agreement someone signs today (docs/HANDOFF.md section 6,
 * docs/ARCHITECTURE.md invariants 2 and 4). `source_template_version_id` is written for
 * provenance and is never dereferenced by this module.
 *
 * Two things are checked before anything is written, and both are refusals rather than
 * repairs:
 *
 * - **The declared digest must be the revision's digest.** A snapshot that says it is
 *   copying revision 12 but names different bytes is describing an agreement that does not
 *   exist, and every attestation would then bind a digest the stored document does not have.
 * - **Parallel mode requires a single signing stage.** A schema with `[["a"], ["b"]]` and a
 *   parallel mode is two contradictory instructions. Honouring one and dropping the other is
 *   the silent-no-op failure AGENTS.md rules out, so the factory refuses both.
 *
 * Recipients come from the copied schema's `recipients`, and their `order_index` from the
 * stage they occupy in `signing_order`. The field-schema validator already guarantees every
 * recipient appears in exactly one stage, so there is no recipient here without a place in
 * the order.
 */
final readonly class EnvelopeFactory
{
    public function __construct(private EnvelopeEventSink $sink) {}

    /**
     * @throws InvalidEnvelopeSnapshot
     */
    public function fromSnapshot(
        Workspace $workspace,
        EnvelopeSourceSnapshot $snapshot,
        ?User $createdBy = null,
    ): Envelope {
        $revision = DocumentRevision::query()->with('document')->find($snapshot->documentRevisionId);

        if ($revision === null) {
            throw InvalidEnvelopeSnapshot::unknownDocumentRevision($snapshot->documentRevisionId);
        }

        if ($revision->document?->workspace_id !== $workspace->getKey()) {
            throw InvalidEnvelopeSnapshot::documentNotInWorkspace($snapshot->documentRevisionId);
        }

        if ($revision->sha256 !== $snapshot->documentSha256) {
            throw InvalidEnvelopeSnapshot::digestMismatch($snapshot->documentSha256, $revision->sha256);
        }

        $stages = count($snapshot->fieldSchema->signingOrder);

        if ($snapshot->signingMode === SigningMode::Parallel && $stages > 1) {
            throw InvalidEnvelopeSnapshot::parallelWithMultipleStages($stages);
        }

        return DB::transaction(function () use ($workspace, $snapshot, $createdBy): Envelope {
            $envelope = new Envelope([
                'workspace_id' => $workspace->getKey(),
                'title' => $snapshot->title,
                'source_template_version_id' => $snapshot->sourceTemplateVersionId,
                'document_revision_id' => $snapshot->documentRevisionId,
                'document_sha256' => $snapshot->documentSha256,
                'field_schema' => $snapshot->canonicalFieldSchema(),
                'field_schema_sha256' => $snapshot->fieldSchemaSha256(),
                'render_settings' => $snapshot->renderSettings,
                'consent_policy_version' => $snapshot->consentPolicyVersion,
                'assurance_level' => $snapshot->assuranceLevel,
                'signing_mode' => $snapshot->signingMode,
                'state' => EnvelopeState::Draft,
                'version' => 1,
                'expiration_hours' => $snapshot->expirationHours,
                'created_by' => $createdBy?->getKey(),
            ]);

            $envelope->save();

            foreach ($snapshot->fieldSchema->recipients as $recipient) {
                $this->createRecipient($envelope, $snapshot, $recipient);
            }

            $this->sink->record($envelope, EnvelopeEvent::Created, [
                'title' => $envelope->title,
                'signing_mode' => $envelope->signing_mode->value,
                'assurance_level' => $envelope->assurance_level->value,
                'document_sha256' => $envelope->document_sha256,
                'field_schema_sha256' => $envelope->field_schema_sha256,
                'recipients' => count($snapshot->fieldSchema->recipients),
            ]);

            return $envelope->load('recipients');
        });
    }

    /**
     * Convenience for callers that hold the snapshot as an array — the templates module and
     * both HTTP surfaces do.
     *
     * @param  array<string, mixed>  $snapshot
     *
     * @throws InvalidEnvelopeSnapshot
     */
    public function fromArray(Workspace $workspace, array $snapshot, ?User $createdBy = null): Envelope
    {
        return $this->fromSnapshot($workspace, EnvelopeSourceSnapshot::fromArray($snapshot), $createdBy);
    }

    private function createRecipient(
        Envelope $envelope,
        EnvelopeSourceSnapshot $snapshot,
        SchemaRecipient $recipient,
    ): EnvelopeRecipient {
        // Guaranteed non-null: `recipient_not_in_signing_order` is a validation error the
        // schema importer already raises, and the snapshot re-imported the document.
        $stage = $snapshot->fieldSchema->stageOf($recipient->id) ?? 1;

        return EnvelopeRecipient::query()->create([
            'envelope_id' => $envelope->getKey(),
            'schema_recipient_id' => $recipient->id,
            'name' => $recipient->name,
            'email' => $recipient->email,
            'order_index' => $stage,
            'state' => RecipientState::Pending,
            'version' => 1,
            // A copy of who this party was when the envelope was created. The schema is
            // immutable; the person behind it is not, and evidence has to record the former.
            'identity_snapshot' => [
                'source' => 'field_schema',
                'schema_recipient_id' => $recipient->id,
                'name' => $recipient->name,
                'email' => $recipient->email,
                'role' => $recipient->role,
            ],
        ]);
    }
}
