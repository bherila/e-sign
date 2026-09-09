<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Preparation\Schema\FieldDefinition;
use App\Domain\Preparation\Schema\FieldType;
use App\Domain\Preparation\Schema\SchemaVersion;
use App\Domain\Signing\Fields\FieldMateriality;
use App\Domain\Signing\Models\Envelope;

/**
 * Synthetic field schemas for the signing suite, and the small mutators the tests need.
 *
 * `tests/Fixtures/schema/nda-two-signers.json` is the canonical schema fixture and stays
 * exactly as it is: it is shared byte-for-byte with the TypeScript suite, so bending it to a
 * state-machine scenario would break the editor's tests for reasons that have nothing to do
 * with the editor. These schemas are built for this module instead, and deliberately cover
 * the combinations the freeze rules turn on — a material field owned by the first signer, a
 * material field owned by a later one, a read-only prefill, and a service-supplied date.
 *
 * All names, addresses, and values are synthetic (AGENTS.md).
 */
final class SigningFixtures
{
    public const CONSENT_VERSION = 'consent-2026-01';

    /**
     * The one declared coordinate space. Anything else is rejected, never reinterpreted.
     *
     * @return array<string, mixed>
     */
    public static function coordinateSpace(): array
    {
        return [
            'unit' => 'pt',
            'origin' => 'top-left',
            'page_box' => 'crop',
            'rotation' => 'displayed',
            'page_index_base' => 1,
        ];
    }

    /**
     * Buyer then seller, one stage each.
     *
     * @return array<string, mixed>
     */
    public static function sequentialTwoSigners(): array
    {
        return [
            'schema_version' => '1.0',
            'document_id' => 'doc_synthetic_sequential_nda',
            'coordinate_space' => self::coordinateSpace(),
            'recipients' => [
                ['id' => 'buyer', 'name' => 'Example Buyer', 'email' => 'buyer@example.test', 'role' => 'Buyer'],
                ['id' => 'seller', 'name' => 'Example Seller', 'email' => 'seller@example.test', 'role' => 'Seller'],
            ],
            'signing_order' => [['buyer'], ['seller']],
            'fields' => [
                // Material, owned by the first stage: fillable, because the freeze happens
                // when the first person accepts and not before.
                self::field('buyer_ack', 'buyer', 'checkbox', 1, 60, 200, 12, 12, true),
                self::field('buyer_notes', 'buyer', 'text', 1, 60, 240, 440, 32, false),
                // Read-only and required: the sender has to supply it before send.
                self::field('agreement_effective_date', 'buyer', 'agreement_date', 1, 400, 96, 140, 18, true, true),
                self::field('buyer_signature', 'buyer', 'signature', 1, 60, 650, 170, 36, true),
                // Second stage. Signer-specific only, so everything they own survives the freeze.
                self::field('seller_signature', 'seller', 'signature', 2, 330, 650, 170, 36, true),
                self::field('seller_title', 'seller', 'title', 2, 330, 700, 170, 18, false),
                // Service-supplied from the seller's own attestation; nobody submits it.
                self::field('seller_signed_at', 'seller', 'signing_date', 2, 330, 730, 170, 18, true, true),
                // Material, owned by the second stage, and optional — which is the only
                // reason the envelope is sendable at all. Used to prove the freeze refuses it.
                self::field('seller_notes', 'seller', 'text', 2, 60, 760, 440, 24, false),
            ],
        ];
    }

    /**
     * Both parties at once, one stage.
     *
     * @return array<string, mixed>
     */
    public static function parallelTwoSigners(): array
    {
        return [
            'schema_version' => '1.0',
            'document_id' => 'doc_synthetic_parallel_nda',
            'coordinate_space' => self::coordinateSpace(),
            'recipients' => [
                ['id' => 'alice', 'name' => 'Example Alice', 'email' => 'alice@example.test', 'role' => 'Party A'],
                ['id' => 'bob', 'name' => 'Example Bob', 'email' => 'bob@example.test', 'role' => 'Party B'],
            ],
            'signing_order' => [['alice', 'bob']],
            'fields' => [
                self::field('shared_term', 'alice', 'text', 1, 60, 240, 440, 32, false),
                self::field('effective_date', 'alice', 'agreement_date', 1, 400, 96, 140, 18, true, true),
                self::field('alice_signature', 'alice', 'signature', 1, 60, 650, 170, 36, true),
                self::field('alice_title', 'alice', 'title', 1, 60, 700, 170, 18, false),
                self::field('bob_signature', 'bob', 'signature', 1, 330, 650, 170, 36, true),
                self::field('bob_company', 'bob', 'company', 1, 330, 700, 170, 18, false),
            ],
        ];
    }

    /**
     * One signer, one required signature. The smallest sendable envelope.
     *
     * @return array<string, mixed>
     */
    public static function singleSigner(): array
    {
        return [
            'schema_version' => '1.0',
            'document_id' => 'doc_synthetic_single_signer',
            'coordinate_space' => self::coordinateSpace(),
            'recipients' => [
                ['id' => 'signer', 'name' => 'Example Signer', 'email' => 'signer@example.test'],
            ],
            'signing_order' => [['signer']],
            'fields' => [
                self::field('signer_signature', 'signer', 'signature', 1, 60, 650, 170, 36, true),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public static function mutateField(array $schema, string $fieldId, array $changes): array
    {
        // An anchor written by a test may use members that arrived in 1.1, and a document may
        // only use what the version it declares declares. Saying so here keeps every caller from
        // having to remember it.
        if (array_key_exists('anchor', $changes) && is_array($changes['anchor'])) {
            $schema['schema_version'] = SchemaVersion::CURRENT;
        }

        foreach ($schema['fields'] as $index => $field) {
            if ($field['id'] === $fieldId) {
                $schema['fields'][$index] = array_replace($field, $changes);

                return $schema;
            }
        }

        throw new \InvalidArgumentException('No field "'.$fieldId.'" in this fixture.');
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public static function mutateRecipient(array $schema, string $recipientId, array $changes): array
    {
        foreach ($schema['recipients'] as $index => $recipient) {
            if ($recipient['id'] === $recipientId) {
                $schema['recipients'][$index] = array_replace($recipient, $changes);

                return $schema;
            }
        }

        throw new \InvalidArgumentException('No recipient "'.$recipientId.'" in this fixture.');
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function removeField(array $schema, string $fieldId): array
    {
        $schema['fields'] = array_values(array_filter(
            $schema['fields'],
            static fn (array $field): bool => $field['id'] !== $fieldId,
        ));

        return $schema;
    }

    /**
     * Every value a sender has to supply before the envelope can be sent.
     *
     * Derived from the same rules the send gate applies rather than hard-coded, so a test
     * that changes a schema does not also have to remember which prefills that implies.
     *
     * @return array<string, mixed>
     */
    public static function senderPrefills(Envelope $envelope): array
    {
        $schema = $envelope->fieldSchema();
        $values = [];

        foreach ($schema->fields as $field) {
            if (! $field->required || FieldMateriality::isServiceSupplied($field->type)) {
                continue;
            }

            if (in_array($field->type, [FieldType::Signature, FieldType::Initials], true)) {
                continue;
            }

            $ownerActsAfterFreeze = $envelope->signing_mode->freezesAtSend()
                || ($schema->stageOf($field->recipientId) ?? 1) > 1;

            if ($field->readOnly || (FieldMateriality::isMaterial($field->type) && $ownerActsAfterFreeze)) {
                $values[$field->id] = self::sampleValue($field);
            }
        }

        return $values;
    }

    /** A value of the right shape for a field's declared type. */
    public static function sampleValue(FieldDefinition $field): mixed
    {
        return match ($field->type) {
            FieldType::Checkbox => true,
            FieldType::AgreementDate, FieldType::SigningDate => '2026-01-31',
            FieldType::Signature, FieldType::Initials => 'data:image/png;base64,'
                .'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
            default => 'Synthetic '.$field->type->value.' value',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function field(
        string $id,
        string $recipientId,
        string $type,
        int $page,
        float $x,
        float $y,
        float $width,
        float $height,
        bool $required,
        bool $readOnly = false,
    ): array {
        return [
            'id' => $id,
            'recipient_id' => $recipientId,
            'type' => $type,
            'page' => $page,
            'rect' => ['x' => $x, 'y' => $y, 'width' => $width, 'height' => $height],
            'required' => $required,
            'read_only' => $readOnly,
        ];
    }
}
