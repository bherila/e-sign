<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Finalization;

use App\Domain\Evidence\Sealing\AssuranceLevel;
use App\Domain\Signing\Fields\CanonicalValue;
use App\Domain\Signing\Fields\MaterialValues;
use App\Domain\Signing\Models\Envelope;
use App\Domain\Signing\Models\EnvelopeFieldValue;
use App\Domain\Signing\Models\EnvelopeRecipient;
use App\Domain\Signing\Models\RecipientAttestation;
use Carbon\CarbonImmutable;

/**
 * Everything a finalization renders from, captured once under the envelope lock.
 *
 * Step 1 of the staged publication (docs/ARCHITECTURE.md) is "capture the immutable
 * finalization input". This is that capture. Nothing in it is re-read while the expensive
 * work happens outside the lock, so a sender editing something concurrently cannot change
 * the document half way through being sealed — and, more importantly, cannot change it
 * between the moment it is sealed and the moment it is published.
 *
 * ## What the snapshot digest covers, and what it does not
 *
 * {@see digest()} covers identifiers and digests only: the revision the document bytes come
 * from and their SHA-256, the field schema digest, one digest per stored field value, the
 * material-values digest, the assurance level, and every attestation's own chained digest.
 * Two runs with the same snapshot digest were rendering the same document from the same
 * evidence, which is the precondition for reusing one run's uploaded bytes in another — the
 * crash-after-upload recovery path.
 *
 * The *values themselves* are deliberately outside the digest and outside what is persisted
 * on the run row. A signature value is a captured image; copying it into an operational table
 * would put signer biometrics-adjacent material somewhere it does not need to be, and would
 * make the run row grow with the document. They are read again from `envelope_field_values`,
 * which is safe because they are frozen by then: if anything did move, the recomputed
 * snapshot digest differs and no bytes are reused.
 *
 * `session_ref` is recorded as a digest rather than in the clear. It identifies a recipient
 * session, and docs/HANDOFF.md section 8 asks for minimized evidence rather than a
 * transcript; the attestation's own digest already binds the raw value.
 */
final readonly class FinalizationInput
{
    /** Bumping this changes every future snapshot digest, so it is a format change. */
    public const ENCODING = 'esign.finalization-input.v1';

    /**
     * @param  list<array{field: string, type: string, value_sha256: string, set_by: string}>  $valueDigests
     * @param  array<string, mixed>  $values  Field id => stored value. Not hashed; see the class docblock.
     * @param  list<array{
     *     recipient: string,
     *     schema_recipient_id: string,
     *     name: string,
     *     email: string,
     *     order_index: int,
     *     signed_at: string|null,
     *     attestation: string,
     *     attestation_sha256: string,
     *     prev_attestation_sha256: string|null,
     *     accepted_at: string,
     *     verification_method: string,
     *     document_sha256: string,
     *     field_schema_sha256: string,
     *     material_values_sha256: string,
     *     consent_policy_version: string,
     *     session_ref_sha256: string
     * }>  $attestations
     */
    public function __construct(
        public string $envelopePublicId,
        public string $workspacePublicId,
        public string $envelopeTitle,
        public int $documentRevisionId,
        public string $documentRevisionPublicId,
        public string $documentSha256,
        public string $fieldSchemaSha256,
        public string $materialValuesSha256,
        public string $consentPolicyVersion,
        public AssuranceLevel $assuranceLevel,
        public array $valueDigests,
        public array $values,
        public array $attestations,
        public string $capturedAt,
    ) {}

    /**
     * Read the capture from a locked envelope.
     *
     * Every read goes through the query builder rather than a loaded relation, so a caller
     * holding a stale model cannot capture content the database no longer holds. Called
     * inside the transaction that holds the envelope lock.
     */
    public static function capture(Envelope $envelope): self
    {
        $revision = $envelope->documentRevision()->firstOrFail();
        $workspace = $envelope->workspace()->firstOrFail();

        /** @var array<string, EnvelopeRecipient> $recipients */
        $recipients = EnvelopeRecipient::query()
            ->where('envelope_id', $envelope->getKey())
            ->get()
            ->keyBy(static fn (EnvelopeRecipient $recipient): string => (string) $recipient->getKey())
            ->all();

        $valueRows = EnvelopeFieldValue::query()
            ->where('envelope_id', $envelope->getKey())
            ->orderBy('schema_field_id')
            ->get();

        $schema = $envelope->fieldSchema();
        $valueDigests = [];
        $values = [];

        foreach ($valueRows as $row) {
            $values[$row->schema_field_id] = $row->value;
            $valueDigests[] = [
                'field' => $row->schema_field_id,
                'type' => $schema->field($row->schema_field_id)?->type->value ?? 'unknown',
                'value_sha256' => $row->value_sha256,
                'set_by' => $row->set_by->value,
            ];
        }

        $attestations = [];

        foreach (RecipientAttestation::query()->where('envelope_id', $envelope->getKey())->orderBy('id')->get() as $attestation) {
            $recipient = $recipients[(string) $attestation->recipient_id] ?? null;

            $attestations[] = [
                'recipient' => $recipient?->public_id ?? '',
                'schema_recipient_id' => $recipient?->schema_recipient_id ?? '',
                'name' => $recipient?->name ?? '',
                'email' => $recipient?->email ?? '',
                'order_index' => (int) ($recipient?->order_index ?? 0),
                'signed_at' => $recipient?->signed_at?->utc()->toIso8601ZuluString() ?? null,
                'attestation' => $attestation->public_id,
                'attestation_sha256' => $attestation->attestation_sha256,
                'prev_attestation_sha256' => $attestation->prev_attestation_sha256,
                'accepted_at' => $attestation->accepted_at->utc()->toIso8601ZuluString(),
                'verification_method' => $attestation->verification_method->value,
                'document_sha256' => $attestation->document_sha256,
                'field_schema_sha256' => $attestation->field_schema_sha256,
                'material_values_sha256' => $attestation->material_values_sha256,
                'consent_policy_version' => $attestation->consent_policy_version,
                'session_ref_sha256' => hash('sha256', $attestation->session_ref),
            ];
        }

        return new self(
            envelopePublicId: $envelope->public_id,
            workspacePublicId: $workspace->public_id,
            envelopeTitle: $envelope->title,
            documentRevisionId: (int) $revision->getKey(),
            documentRevisionPublicId: $revision->public_id,
            documentSha256: $envelope->document_sha256,
            fieldSchemaSha256: $envelope->field_schema_sha256,
            materialValuesSha256: MaterialValues::forEnvelope($envelope),
            consentPolicyVersion: $envelope->consent_policy_version,
            assuranceLevel: $envelope->assurance_level,
            valueDigests: $valueDigests,
            values: $values,
            attestations: $attestations,
            capturedAt: CarbonImmutable::now()->utc()->toIso8601ZuluString(),
        );
    }

    /**
     * The canonical form that is hashed and stored on the run row.
     *
     * `captured_at` is outside the hashed body on purpose: two attempts at the same envelope
     * are the same *input* even though they were captured at different instants, and the
     * whole point of the digest is to recognise that.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $body = $this->hashedBody();

        return [
            ...$body,
            'captured_at' => $this->capturedAt,
            'snapshot_sha256' => hash('sha256', CanonicalValue::encode($body)),
        ];
    }

    /** SHA-256 over the canonical hashed body. Equal digests mean equal rendering inputs. */
    public function digest(): string
    {
        return hash('sha256', CanonicalValue::encode($this->hashedBody()));
    }

    /**
     * @return list<string> Public ids of every attestation this finalization covers.
     */
    public function attestationIds(): array
    {
        return array_values(array_map(
            static fn (array $attestation): string => (string) $attestation['attestation'],
            $this->attestations,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function hashedBody(): array
    {
        return [
            'encoding' => self::ENCODING,
            'envelope' => $this->envelopePublicId,
            'workspace' => $this->workspacePublicId,
            'document_revision' => $this->documentRevisionPublicId,
            'document_sha256' => $this->documentSha256,
            'field_schema_sha256' => $this->fieldSchemaSha256,
            'material_values_sha256' => $this->materialValuesSha256,
            'consent_policy_version' => $this->consentPolicyVersion,
            'assurance_level' => $this->assuranceLevel->value,
            'field_values' => $this->valueDigests,
            'attestations' => $this->attestations,
        ];
    }
}
