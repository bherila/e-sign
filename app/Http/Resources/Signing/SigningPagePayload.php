<?php

declare(strict_types=1);

namespace App\Http\Resources\Signing;

use App\Domain\Preparation\Documents\Models\Document;
use App\Domain\Preparation\Schema\FieldDefinition;
use App\Domain\Signing\Capture\ConsentPolicy;
use App\Domain\Signing\Capture\SignatureImage;
use App\Domain\Signing\Fields\FieldMateriality;
use App\Domain\Signing\Fields\MaterialValues;
use App\Domain\Signing\Models\Envelope;
use App\Domain\Signing\Models\EnvelopeFieldValue;
use App\Domain\Signing\Models\EnvelopeRecipient;
use App\Domain\Signing\Sessions\GuestSigningContext;

/**
 * Everything the signing page's React island is given, as one array.
 *
 * The same pattern the field editor uses: the server writes a document into one `data-*`
 * attribute and the client never constructs a URL, never guesses a page size, and never
 * decides who owns a field. Three of those are security properties rather than
 * conveniences — a client that decides field ownership decides who may fill what.
 *
 * ## What is and is not sent
 *
 * Every field in the schema is sent, because the signer has to see the whole agreement,
 * including the parts another party will complete. What distinguishes them is
 * `own_field_ids`: the ids this recipient may write. The client greys out the rest, and the
 * server refuses them regardless — `EnvelopeStateMachine::submitValues()` checks ownership
 * itself (docs/ARCHITECTURE.md invariant 1), so the list is a rendering hint, never an
 * authorization.
 *
 * Other recipients' *values* are sent too, because they are part of what this person is
 * agreeing to and a signature bound to a material digest that includes values the signer was
 * not shown would be a signature on unseen text. Their email addresses are not: a
 * counterparty needs to know who else is on the agreement, not how to reach them.
 *
 * `reviewed.material_values_sha256` and `reviewed.envelope_version` are the two values the
 * acceptance has to quote back (docs/ARCHITECTURE.md invariant 2). They are computed here,
 * at render time, from the same helpers `accept()` will compare against — so the page states
 * what it displayed rather than what it hopes is current, and a concurrent edit makes the
 * acceptance fail its staleness check instead of binding to text nobody saw.
 */
final class SigningPagePayload
{
    public function __construct(
        private readonly ConsentPolicy $consent,
        private readonly SignatureImage $images,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(GuestSigningContext $context, Document $document, string $csrfToken): array
    {
        $envelope = $context->envelope;
        $recipient = $context->recipient;
        $schema = $envelope->fieldSchema();

        $values = $envelope->fieldValues()->get();
        $consent = $this->consent->forRecordedVersion($envelope->consent_policy_version);

        return [
            'envelope' => [
                'id' => $envelope->public_id,
                'title' => $envelope->title,
                'state' => $envelope->state->value,
                'sender' => $this->senderName($envelope),
                'expires_at' => $envelope->expires_at?->toIso8601String(),
            ],
            'recipient' => [
                'id' => $recipient->public_id,
                'name' => $recipient->name,
                'schema_recipient_id' => $recipient->schema_recipient_id,
                'state' => $recipient->state->value,
            ],
            'parties' => $this->parties($envelope, $recipient),
            'document' => [
                'title' => $document->title,
                'page_count' => $document->page_count,
            ],
            'pages' => $this->pageGeometry($document),
            'field_schema' => $this->redactedSchema($envelope),
            'own_field_ids' => $this->ownFieldIds($schema->fieldsFor($recipient->schema_recipient_id)),
            'required_signature_field_ids' => $this->requiredSignatureFieldIds(
                $schema->fieldsFor($recipient->schema_recipient_id),
            ),
            // Service-supplied fields are shown as "captured when you sign" rather than as an
            // empty box: FieldMateriality::isServiceSupplied() says nobody submits them, and a
            // form control for one would be a lie about who decides its value.
            'service_supplied_field_ids' => $this->serviceSuppliedFieldIds($schema->fields),
            'values' => $this->values($values),
            'reviewed' => [
                'material_values_sha256' => MaterialValues::forEnvelope($envelope),
                'envelope_version' => $envelope->version,
            ],
            'consent' => [
                'recorded_version' => $consent->recordedVersion,
                'text_version' => $consent->textVersion,
                'matches_recorded_version' => $consent->matchesRecordedVersion(),
                'html' => $consent->html,
            ],
            'limits' => [
                'max_signature_image_bytes' => $this->images->maxBytes(),
                'max_signature_image_width' => $this->images->maxWidth(),
                'max_signature_image_height' => $this->images->maxHeight(),
            ],
            'urls' => [
                'document' => route('signing.session.document', ['envelope' => $envelope->public_id]),
                'values' => route('signing.session.values', ['envelope' => $envelope->public_id]),
                'accept' => route('signing.session.accept', ['envelope' => $envelope->public_id]),
                'decline' => route('signing.session.decline', ['envelope' => $envelope->public_id]),
                'reload' => route('signing.session.show', ['envelope' => $envelope->public_id]),
            ],
            'csrf_token' => $csrfToken,
        ];
    }

    /**
     * The copied field schema with every party's email address removed.
     *
     * The schema carries `recipients[].email` because that is how it was imported, and the
     * signing page has no use for any of them: it renders names and roles from `parties`, and
     * field ownership is by `recipient_id` and never by address (AGENTS.md). Shipping the
     * originals would hand every counterparty a contact list because they were sent a link.
     *
     * The addresses are replaced rather than deleted, with a `.invalid` placeholder that RFC
     * 2606 reserves and no resolver will ever answer. Two reasons for replacing rather than
     * dropping: the client parses this through the same `parseFieldSchema` the editor uses,
     * which requires the property, and a placeholder that is visibly a placeholder says the
     * value was withheld — where a plausible-looking substitute would be a lie in a document
     * somebody is about to sign against.
     *
     * @return array<string, mixed>
     */
    private function redactedSchema(Envelope $envelope): array
    {
        $schema = $envelope->field_schema;

        if (! is_array($schema['recipients'] ?? null)) {
            return $schema;
        }

        foreach ($schema['recipients'] as $index => $recipient) {
            if (is_array($recipient) && isset($recipient['id'])) {
                $schema['recipients'][$index]['email'] = $recipient['id'].'@redacted.invalid';
            }
        }

        return $schema;
    }

    private function senderName(Envelope $envelope): string
    {
        $name = trim((string) ($envelope->workspace()->first()?->name ?? ''));

        return $name === '' ? (string) config('app.name') : $name;
    }

    /**
     * Who else is on this agreement, by display name and role only.
     *
     * No addresses. Everyone signing a contract is entitled to know who the other parties
     * are; nobody is entitled to a contact list because they were sent a link.
     *
     * @return list<array<string, mixed>>
     */
    private function parties(Envelope $envelope, EnvelopeRecipient $self): array
    {
        $schema = $envelope->fieldSchema();

        return $envelope->recipients()->get()
            ->map(static fn (EnvelopeRecipient $party): array => [
                'schema_recipient_id' => $party->schema_recipient_id,
                'name' => $party->name,
                'role' => $schema->recipient($party->schema_recipient_id)?->role,
                'state' => $party->state->value,
                'is_you' => $party->getKey() === $self->getKey(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<FieldDefinition>  $fields
     * @return list<string>
     */
    private function ownFieldIds(array $fields): array
    {
        return array_values(array_map(
            static fn (FieldDefinition $field): string => $field->id,
            array_filter(
                $fields,
                // Read-only and service-supplied fields belong to this recipient in the
                // schema and are still not theirs to type into. The state machine refuses
                // both; excluding them here means the page does not offer an input that
                // cannot be submitted.
                static fn (FieldDefinition $field): bool => ! $field->readOnly
                    && ! FieldMateriality::isServiceSupplied($field->type),
            ),
        ));
    }

    /**
     * The signature and initials fields this recipient must complete.
     *
     * Echoed back by the acceptance form and re-derived on the server before an assent is
     * recorded, so "every signature box was filled" is checked against the schema rather
     * than against the client's word for it.
     *
     * @param  list<FieldDefinition>  $fields
     * @return list<string>
     */
    private function requiredSignatureFieldIds(array $fields): array
    {
        return array_values(array_map(
            static fn (FieldDefinition $field): string => $field->id,
            array_filter(
                $fields,
                static fn (FieldDefinition $field): bool => $field->required
                    && ! $field->readOnly
                    && in_array($field->type->value, ['signature', 'initials'], true),
            ),
        ));
    }

    /**
     * @param  list<FieldDefinition>  $fields
     * @return list<string>
     */
    private function serviceSuppliedFieldIds(array $fields): array
    {
        return array_values(array_map(
            static fn (FieldDefinition $field): string => $field->id,
            array_filter(
                $fields,
                static fn (FieldDefinition $field): bool => FieldMateriality::isServiceSupplied($field->type),
            ),
        ));
    }

    /**
     * @param  iterable<EnvelopeFieldValue>  $values
     * @return array<string, mixed>
     */
    private function values(iterable $values): array
    {
        $map = [];

        foreach ($values as $value) {
            $map[$value->schema_field_id] = $value->value;
        }

        return $map;
    }

    /**
     * The displayed geometry of every page, from the document's preflight report.
     *
     * Identical in shape to what the field editor is given — same keys, same meanings, and
     * consumed by the same `PageTransform` on the client, which is the point: field
     * rectangles are validated against the displayed page size the report recorded, so a
     * signing page that derived its own would be a second opinion about where a signature
     * box is. See docs/preparation/coordinate-space.md.
     *
     * @return list<array<string, mixed>>
     */
    private function pageGeometry(Document $document): array
    {
        /** @var array<string, mixed> $report */
        $report = $document->preflight_report ?? [];
        $pages = $report['pages'] ?? [];

        if (! is_array($pages)) {
            return [];
        }

        $geometry = [];

        foreach ($pages as $page) {
            if (! is_array($page) || ! is_array($page['crop_box'] ?? null)) {
                continue;
            }

            $geometry[] = [
                'page' => (int) ($page['page'] ?? 0),
                'crop_box' => array_map(static fn (mixed $v): float => (float) $v, array_values($page['crop_box'])),
                'rotation' => (int) ($page['rotation'] ?? 0),
                'user_unit' => (float) ($page['user_unit'] ?? 1.0),
                'native_width' => (float) ($page['native_width'] ?? 0.0),
                'native_height' => (float) ($page['native_height'] ?? 0.0),
            ];
        }

        return $geometry;
    }
}
