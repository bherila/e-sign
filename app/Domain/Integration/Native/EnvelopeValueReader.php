<?php

declare(strict_types=1);

namespace App\Domain\Integration\Native;

use App\Domain\Preparation\Schema\FieldDefinition;
use App\Domain\Signing\Fields\FieldMateriality;
use App\Domain\Signing\Models\Envelope;
use App\Domain\Signing\Models\EnvelopeFieldValue;
use App\Domain\Signing\Models\EnvelopeRecipient;
use App\Domain\Signing\Models\RecipientAttestation;
use Carbon\CarbonImmutable;

/**
 * The envelope's values, in the order the field schema declares them.
 *
 * Schema order rather than insertion order, because it is the order the fields appear in the
 * agreement and it is stable across reads; the order values happened to be written in is an
 * implementation detail of who typed first.
 *
 * A field with no value is reported with `value: null` rather than omitted. A caller
 * reconciling an envelope needs to see that `buyer_notes` exists and is empty, and an absent
 * key would be indistinguishable from a field the schema never had.
 *
 * `signing_date` is the one field that is never in `envelope_field_values`. It is derived
 * here from the owning recipient's attestation, which is where the fact actually lives
 * (App\Domain\Signing\Fields\FieldMateriality: a value derived from an immutable attestation
 * must not get a mutable second copy).
 */
final readonly class EnvelopeValueReader
{
    /**
     * @return list<FieldValueView>
     */
    public function read(Envelope $envelope, bool $includeImages): array
    {
        $stored = EnvelopeFieldValue::query()
            ->where('envelope_id', $envelope->getKey())
            ->get()
            ->keyBy('schema_field_id');

        $acceptedAt = $this->acceptanceInstants($envelope);
        $views = [];

        foreach ($envelope->fieldSchema()->fields as $field) {
            if (FieldMateriality::isServiceSupplied($field->type)) {
                $views[] = FieldValueView::serviceSupplied(
                    $field,
                    $acceptedAt[$field->recipientId] ?? null,
                );

                continue;
            }

            $views[] = $this->storedView($field, $stored->get($field->id), $includeImages);
        }

        return $views;
    }

    private function storedView(FieldDefinition $field, ?EnvelopeFieldValue $row, bool $includeImages): FieldValueView
    {
        return FieldValueView::stored(
            $field,
            $row?->value,
            $row?->set_by->value ?? 'sender',
            $row?->frozen_at,
            $includeImages,
        );
    }

    /**
     * When each recipient accepted, keyed by their schema recipient id.
     *
     * The latest attestation wins. There is normally exactly one per recipient; taking the
     * latest rather than assuming it keeps this read correct if a replayed acceptance ever
     * writes a second.
     *
     * @return array<string, CarbonImmutable>
     */
    private function acceptanceInstants(Envelope $envelope): array
    {
        $recipients = $envelope->recipients()->get()->keyBy('id');

        if ($recipients->isEmpty()) {
            return [];
        }

        $instants = [];

        $attestations = RecipientAttestation::query()
            ->where('envelope_id', $envelope->getKey())
            ->orderBy('id')
            ->get();

        foreach ($attestations as $attestation) {
            $recipient = $recipients->get($attestation->recipient_id);

            if ($recipient instanceof EnvelopeRecipient) {
                $instants[$recipient->schema_recipient_id] = $attestation->accepted_at;
            }
        }

        return $instants;
    }
}
