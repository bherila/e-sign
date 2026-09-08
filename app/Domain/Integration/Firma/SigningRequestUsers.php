<?php

declare(strict_types=1);

namespace App\Domain\Integration\Firma;

use App\Domain\Integration\Native\FieldValueView;
use App\Domain\Preparation\Schema\FieldDefinition;
use App\Domain\Signing\Models\Envelope;
use App\Domain\Signing\Models\EnvelopeRecipient;
use stdClass;

/**
 * `GET /signing-requests/{id}/users`: the parties, their completion, and their readiness.
 *
 * The recipient facts — id, name, email, order, `finished_on`, `declined_on`,
 * `decline_reason` — come from App\Domain\Delivery\Events\SigningRequestPayload, the same
 * builder every `signing_request.*` webhook uses. A receiver reading a webhook body and a
 * caller polling this endpoint therefore cannot be told two different stories about who
 * signed and when, which is the drift AGENTS.md forbids.
 *
 * ## Three deliberate differences from upstream, all recorded in the matrix
 *
 * **`first_name` and `last_name` are null.** This product stores one display name, because
 * splitting a person's name into parts is a guess about that person (`docs/HANDOFF.md` §2).
 * The keys are present so a consumer that reads them gets null rather than an undefined
 * index, and `name` carries the whole thing. Upstream lists `first_name` as required; a
 * fabricated half of somebody's name would be worse than an honest null.
 *
 * **`designation` is always `Signer`.** Upstream's enum is `Signer|Approver|CC`. Every party
 * modelled here signs; emitting `Approver` for somebody nothing treats as an approver would
 * put an authority in the payload that nothing enforces.
 *
 * **The contact and profile block is null.** `phone_number`, `street_address`, `city`,
 * `state_province`, `postal_code`, `country`, `title`, `company` and `custom_fields` are a
 * contact record this service does not keep: it binds identity on the attestation, not on a
 * stored address book. A field's `title` or `company` *value* is a field value and appears on
 * `/fields`, where it belongs.
 *
 * ## Readiness
 *
 * `ready_to_send` is what upstream's send guard reads, and it is answered here from the same
 * two facts the state machine's own gate uses: the party has a name and an address to write
 * to, and every field the sender owes them has a value. `required_read_only_fields` lists
 * those fields with `has_value`, so a caller that gets a refused `/send` can see which
 * prefill is missing without a second request.
 */
final readonly class SigningRequestUsers
{
    /**
     * Identity attributes a party must have before an invitation can be addressed to them.
     *
     * Upstream's own `required_fields` "always includes `email` and `first_name`". There is
     * no `first_name` here, so the honest list is `email` and `name` — the two things this
     * service actually refuses to send without.
     *
     * @var list<string>
     */
    public const REQUIRED_IDENTITY_FIELDS = ['email', 'name'];

    public function __construct(private SigningRequestFields $fields) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function results(Envelope $envelope): array
    {
        $views = $this->fields->viewsByFieldId($envelope, includeImages: false);
        $schema = $envelope->fieldSchema();
        $rows = [];

        if (! $envelope->relationLoaded('recipients')) {
            $envelope->setRelation('recipients', $envelope->recipients()->get());
        }

        foreach ($envelope->getRelation('recipients') as $recipient) {
            /** @var EnvelopeRecipient $recipient */
            $readOnly = $this->requiredReadOnlyFields($schema->fieldsFor($recipient->schema_recipient_id), $views);
            $missing = $this->missingIdentityFields($recipient);

            $rows[] = [
                'id' => $recipient->public_id,
                'name' => $recipient->name,
                'email' => $recipient->email,
                // See the class docblock: one display name is stored and nothing here
                // guesses which part of it is which.
                'first_name' => null,
                'last_name' => null,
                'designation' => FirmaProfile::DESIGNATION,
                'order' => $recipient->order_index,
                'finished_on' => $recipient->signed_at?->toIso8601String(),
                'declined_on' => $recipient->declined_at?->toIso8601String(),
                'decline_reason' => $recipient->decline_reason,
                'phone_number' => null,
                'street_address' => null,
                'city' => null,
                'state_province' => null,
                'postal_code' => null,
                'country' => null,
                'title' => null,
                'company' => null,
                'custom_fields' => new stdClass,
                'required_fields' => self::REQUIRED_IDENTITY_FIELDS,
                'missing_fields' => $missing,
                'required_read_only_fields' => $readOnly,
                'ready_to_send' => $missing === [] && ! self::anyMissingValue($readOnly),
            ];
        }

        return $rows;
    }

    /**
     * The fields the **sender** owes this party before the request can go out.
     *
     * A read-only required field is the sender's to fill: the recipient cannot type in it,
     * so leaving it empty would put an incomplete agreement in front of them. Everything
     * else on the party's list is theirs to complete after they open it, and is therefore
     * not a readiness question.
     *
     * @param  list<FieldDefinition>  $fields
     * @param  array<string, FieldValueView>  $views
     * @return list<array{variable_name: string|null, variable_defined_name: string|null, field_type: string, has_value: bool}>
     */
    private function requiredReadOnlyFields(array $fields, array $views): array
    {
        $rows = [];

        foreach ($fields as $field) {
            if (! $field->required || ! $field->readOnly) {
                continue;
            }

            $view = $views[$field->id] ?? null;

            $rows[] = [
                'variable_name' => SigningRequestFields::variableName($field),
                'variable_defined_name' => null,
                'field_type' => FirmaFieldType::outbound($field->type),
                'has_value' => $view instanceof FieldValueView
                    && SigningRequestFields::finalValue($view, false) !== null,
            ];
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function missingIdentityFields(EnvelopeRecipient $recipient): array
    {
        $missing = [];

        if (trim((string) $recipient->email) === '') {
            $missing[] = 'email';
        }

        if (trim((string) $recipient->name) === '') {
            $missing[] = 'name';
        }

        return $missing;
    }

    /**
     * @param  list<array{has_value: bool, ...}>  $readOnly
     */
    private static function anyMissingValue(array $readOnly): bool
    {
        foreach ($readOnly as $row) {
            if ($row['has_value'] === false) {
                return true;
            }
        }

        return false;
    }
}
