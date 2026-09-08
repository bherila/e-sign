<?php

declare(strict_types=1);

namespace App\Domain\Integration\Firma;

use App\Domain\Preparation\Schema\FieldType;

/**
 * The projection between native field types and the profile's.
 *
 * Disagreement D10 records that the pinned document carries **five** different field-type
 * enums which disagree with each other: `Field` (the write body) has `image`, `initials` and
 * `textarea` but not `file`, `radio_buttons` or `url`; `SigningRequestField` (the read shape)
 * has the second set and not the first; the inline PATCH enum is their union minus `image`;
 * `SigningRequestCreateField` uniquely adds `number`; `AnchorTag` uniquely adds `radio`.
 * There is no single upstream vocabulary to be compatible with.
 *
 * So this class does two narrow things and refuses to do a third.
 *
 * ## Reading: project onto what the fixtures actually show
 *
 * The recorded responses use exactly `date`, `signature` and `text`
 * (`tests/Fixtures/firma/firma-compat-v1/`), and those plus `initial` and `checkbox` are
 * what the facade emits. `name`, `company` and `title` are native types whose *rendering* is
 * a line of text, and they are emitted as `text`: the alternative is guessing at enum
 * members of a document that is not vendored here, and a consumer switching on a value we
 * invented would break on the first real response. The semantic handle survives in
 * `variable_name`, which is where the consumer already reads it.
 *
 * ## Writing: accept the union, refuse the rest loudly
 *
 * An inbound type is normalised (upstream documents `initials`→`initial` and
 * `textarea`→`text_area`; both spellings arrive in practice) and then has to land on a
 * native type. One that cannot — `file`, `radio_buttons`, `url`, `image`, `stamp`, `number`,
 * `dropdown` — is `501` naming the type, never silently dropped and never coerced into a
 * text box. A field a signer was never asked to complete is the failure mode
 * `docs/HANDOFF.md` section 7 exists to prevent.
 */
final class FirmaFieldType
{
    /**
     * Inbound spellings, normalised, mapped onto native types.
     *
     * @var array<string, FieldType>
     */
    private const INBOUND = [
        'signature' => FieldType::Signature,
        'initial' => FieldType::Initials,
        'initials' => FieldType::Initials,
        'text' => FieldType::Text,
        'text_area' => FieldType::Text,
        'textarea' => FieldType::Text,
        'name' => FieldType::Name,
        'full_name' => FieldType::Name,
        'company' => FieldType::Company,
        'title' => FieldType::Title,
        'date' => FieldType::AgreementDate,
        'date_signed' => FieldType::SigningDate,
        'signing_date' => FieldType::SigningDate,
        'checkbox' => FieldType::Checkbox,
    ];

    /**
     * Types the profile declares that this build cannot place on a document.
     *
     * Listed rather than implied, so the 501 message can name what is missing instead of
     * saying "unknown type" about something the upstream document really does declare.
     *
     * @var list<string>
     */
    public const DECLARED_BUT_UNSUPPORTED = [
        'dropdown', 'file', 'image', 'number', 'radio', 'radio_buttons', 'stamp', 'url',
    ];

    /**
     * The profile's `type` / `field_type` for a native field. See the class docblock.
     */
    public static function outbound(FieldType $type): string
    {
        return match ($type) {
            FieldType::Signature => 'signature',
            FieldType::Initials => 'initial',
            FieldType::AgreementDate, FieldType::SigningDate => 'date',
            FieldType::Checkbox => 'checkbox',
            FieldType::Text, FieldType::Name, FieldType::Company, FieldType::Title => 'text',
        };
    }

    /**
     * @throws FirmaException 501 for a type the profile declares and this build cannot place.
     */
    public static function inbound(string $type): FieldType
    {
        $normalised = str_replace('-', '_', mb_strtolower(trim($type)));

        if (isset(self::INBOUND[$normalised])) {
            return self::INBOUND[$normalised];
        }

        if (in_array($normalised, self::DECLARED_BUT_UNSUPPORTED, true)) {
            throw FirmaException::unsupported(
                'fields[].type='.$normalised,
                'Field type "'.$normalised.'" is declared by this profile and is not implemented in this '
                .'build, so the request is refused rather than accepted with the field silently dropped or '
                .'turned into a text box.',
                ['supported_types' => array_values(array_unique(array_map(
                    static fn (FieldType $native): string => self::outbound($native),
                    self::INBOUND,
                )))],
            );
        }

        throw FirmaException::of(
            FirmaErrorCode::InvalidRequest,
            'Field type "'.$type.'" is not a type this profile recognises.',
            ['type' => $type, 'declared_but_unsupported' => self::DECLARED_BUT_UNSUPPORTED],
        );
    }
}
