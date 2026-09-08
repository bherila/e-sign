<?php

declare(strict_types=1);

namespace App\Domain\Signing\Fields;

use App\Domain\Preparation\Schema\FieldDefinition;
use App\Domain\Preparation\Schema\FieldType;
use App\Domain\Signing\Envelopes\EnvelopeStateMachine;

/**
 * Which fields are part of the agreement's shared content, and which belong to one signer.
 *
 * This is the classification docs/ARCHITECTURE.md invariant 4 rests on: "After the first
 * acceptance, substantive content is frozen; only anticipated signer-specific fields may be
 * added." The native field schema (docs/preparation/field-schema.md) does not carry a
 * per-field `signer_specific` flag, and adding one would be a schema version bump plus a new
 * way for a sender to mislead a signer. It carries something better — a closed list of field
 * *types* — and the classification follows from the type, so it is the same for every
 * document and cannot be set wrongly on one field.
 *
 * | Type | Class | Why |
 * |---|---|---|
 * | `signature`, `initials` | signer-specific | the mark is the person's own |
 * | `name`, `title`, `company` | signer-specific | how this signer identifies themselves and in what capacity |
 * | `signing_date` | signer-specific, service-supplied | when this person accepted, taken from the server clock |
 * | `text`, `checkbox` | **material** | free text and ticked terms are contract content |
 * | `agreement_date` | **material** | the agreement's own effective date, identical for everyone |
 *
 * `text` and `checkbox` are material even when a later signer owns them, and that is the
 * conservative reading on purpose: free text on an agreement changes what the agreement
 * says, and a tick box next to a term is the term being accepted. A workflow that needs a
 * second signer to type something therefore has to collect it before the first acceptance —
 * which the send gate checks for, rather than letting the envelope deadlock later
 * ({@see EnvelopeStateMachine::send()}).
 *
 * `agreement_date` is material rather than signer-specific despite living next to a
 * signature: docs/preparation/field-schema.md defines it as "the agreement's own effective
 * date, set by the sender, identical for every recipient".
 */
final class FieldMateriality
{
    /**
     * Part of the shared agreement content, and therefore covered by the material digest and
     * frozen at the first acceptance.
     */
    public static function isMaterial(FieldType $type): bool
    {
        return ! self::isSignerSpecific($type);
    }

    /**
     * Belongs to exactly one signer and does not change what anybody else agreed to, so it
     * may still be completed after the content freezes — by that signer, if they have not
     * signed yet.
     */
    public static function isSignerSpecific(FieldType $type): bool
    {
        return match ($type) {
            FieldType::Signature,
            FieldType::Initials,
            FieldType::Name,
            FieldType::Title,
            FieldType::Company,
            FieldType::SigningDate => true,
            FieldType::Text,
            FieldType::Checkbox,
            FieldType::AgreementDate => false,
        };
    }

    /**
     * Filled by the service at rendering time from evidence it already holds, so nobody has
     * to supply it and the send gate must not demand it.
     *
     * Only `signing_date`, which is `recipient_attestations.accepted_at` for the recipient
     * who owns the field. It is never written into `envelope_field_values`: a value the
     * service derives from an immutable attestation should be derived, not copied into a
     * mutable table where it could disagree with its own source.
     */
    public static function isServiceSupplied(FieldType $type): bool
    {
        return $type === FieldType::SigningDate;
    }

    /**
     * Field ids of the material fields of a schema, in schema order.
     *
     * @param  list<FieldDefinition>  $fields
     * @return list<string>
     */
    public static function materialFieldIds(array $fields): array
    {
        return array_values(array_map(
            static fn (FieldDefinition $field): string => $field->id,
            array_filter($fields, static fn (FieldDefinition $field): bool => self::isMaterial($field->type)),
        ));
    }
}
