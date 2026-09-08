<?php

declare(strict_types=1);

namespace App\Domain\Integration\Firma;

use App\Domain\Identity\Audit\AuditActor;
use App\Domain\Identity\Credentials\ServiceCredential;
use App\Domain\Identity\Models\Workspace;
use App\Domain\Integration\Native\EnvelopeService;
use App\Domain\Integration\Native\NewEnvelope;
use App\Domain\Preparation\Documents\DocumentIntake;
use App\Domain\Preparation\Documents\Models\Document;
use App\Domain\Preparation\Documents\Models\DocumentRevision;
use App\Domain\Preparation\Schema\FieldSchemaDocument;
use App\Domain\Preparation\Schema\Recipient;
use App\Domain\Preparation\Templates\Models\TemplateVersion;
use App\Domain\Signing\Models\Envelope;

/**
 * `POST /signing-requests` and `POST /signing-requests/create-and-send`.
 *
 * Both routes end in the same place: App\Domain\Integration\Native\EnvelopeService, the
 * service the native API's `POST /envelopes` calls. Nothing about the signing rules, the
 * snapshot, the tenancy check or the send gate is re-implemented here — AGENTS.md's "one
 * state machine" would be a slogan otherwise. What this class owns is the translation, and
 * it is a real translation because the two request shapes have almost nothing in common:
 *
 * | Profile | Native |
 * |---|---|
 * | `template_id` — our public id **or** an imported alias | `template_version_id` |
 * | `document` — base64 PDF in the JSON body | `document_id` of an already-ingested document |
 * | `fields[].position` in percent of the page | `field_schema.fields[].rect` in points |
 * | `recipients[].first_name` + `last_name` + `order` | schema recipient ids and stages |
 * | `fields[].variable_name` → `final_value` | `values` keyed by schema field id |
 *
 * ## Two sources, two meanings for `fields[]`
 *
 * From a **template**, the template already says where every field is, so `fields[]` can
 * only be a set of prefills: each entry names a field by `variable_name` or by id and gives
 * it a value. From a **document**, there are no fields yet, so `fields[]` places them — and
 * may prefill them in the same entry. Upstream conflates the two under one member name; the
 * facade keeps them apart by the only thing that distinguishes them, which is what the
 * request supplied as its source.
 *
 * An override that matches no field is an error either way. Upstream silently ignores one,
 * and the capability matrix rules that out: a dropped prefill is a blank in an executed
 * agreement that nobody noticed until a counterparty asked about it.
 *
 * ## The document is ingested, not trusted
 *
 * A base64 `document` goes through {@see DocumentIntake}, which is the same intake the
 * upload UI uses: the bytes are staged, hashed, really parsed by the preflight parser,
 * written and read back, and recorded with their report. An encrypted, already-signed, or
 * structurally unsupported PDF is refused here with the parser's own findings, before any
 * envelope exists. `create-and-send` therefore cannot leave a half-built draft behind — the
 * atomicity upstream's own `500` description promises.
 */
final readonly class SigningRequestCreation
{
    public function __construct(
        private EnvelopeService $envelopes,
        private SigningRequestLocator $locator,
        private FieldPlacement $placement,
        private PageGeometryReader $pages,
        private DocumentIntake $intake,
    ) {}

    /**
     * @param  array<string, mixed>  $body  Validated request body.
     *
     * @throws FirmaException
     */
    public function create(Workspace $workspace, ServiceCredential $credential, array $body): Envelope
    {
        UnsupportedOptions::guard($body);

        $templateId = self::string($body, 'template_id');
        $document = self::string($body, 'document');

        if (($templateId === null) === ($document === null)) {
            throw FirmaException::of(
                FirmaErrorCode::InvalidRequest,
                'Supply exactly one of `template_id` or `document`. A request with both does not say which '
                .'agreement is meant, and one with neither has nothing to sign.',
            );
        }

        return $templateId !== null
            ? $this->fromTemplate($workspace, $credential, $body, $templateId)
            : $this->fromDocument($workspace, $credential, $body, $document ?? '');
    }

    /* ------------------------------------------------------------------ template */

    /**
     * @param  array<string, mixed>  $body
     *
     * @throws FirmaException
     */
    private function fromTemplate(
        Workspace $workspace,
        ServiceCredential $credential,
        array $body,
        string $templateId,
    ): Envelope {
        $version = $this->locator->templateVersion($workspace, $templateId);
        $schema = $version->fieldSchemaDocument();

        return $this->envelopes->create(
            $workspace,
            new NewEnvelope(
                templateVersionId: $version->public_id,
                title: self::string($body, 'name'),
                recipients: $this->templateContacts($schema->recipients, $version, $body),
                values: $this->prefills($schema, is_array($body['fields'] ?? null) ? $body['fields'] : []),
                expiresInHours: self::expirationHours($body),
                expirySpecified: array_key_exists('expiration_hours', $body),
            ),
            $credential,
        );
    }

    /**
     * Match the request's recipients onto the template's declared parties.
     *
     * Upstream matches template users "by `template_user_id` or `order`", and both are
     * accepted here — `template_user_id` being this application's schema recipient id, which
     * is what a template's parties are actually called. `order` is the party's stage in the
     * template's signing order.
     *
     * Only the contact details are overridable. A template's `order` and the party's place in
     * the signing sequence come from the template and are never taken from the request, which
     * is upstream's rule as well: a request that could reorder a template's signers could
     * bind the wrong party to the wrong signature block.
     *
     * @param  list<Recipient>  $declared
     * @param  array<string, mixed>  $body
     * @return list<array{id: string, name?: string|null, email?: string|null}>
     *
     * @throws FirmaException
     */
    private function templateContacts(array $declared, TemplateVersion $version, array $body): array
    {
        $requested = is_array($body['recipients'] ?? null) ? array_values($body['recipients']) : [];

        if ($requested === []) {
            return [];
        }

        $schema = $version->fieldSchemaDocument();
        $byId = [];
        $byStage = [];

        foreach ($declared as $recipient) {
            $byId[mb_strtolower($recipient->id)] = $recipient->id;
            $stage = $schema->stageOf($recipient->id);

            if ($stage !== null) {
                $byStage[$stage][] = $recipient->id;
            }
        }

        $contacts = [];

        foreach ($requested as $index => $recipient) {
            $named = self::string($recipient, 'template_user_id') ?? self::string($recipient, 'id');
            $resolved = $named !== null ? ($byId[mb_strtolower($named)] ?? null) : null;

            if ($resolved === null) {
                $order = $recipient['order'] ?? null;
                $stage = is_int($order) || (is_string($order) && ctype_digit((string) $order)) ? (int) $order : null;
                $candidates = $stage === null ? [] : ($byStage[$stage] ?? []);

                if (count($candidates) === 1) {
                    $resolved = $candidates[0];
                }
            }

            if ($resolved === null) {
                throw FirmaException::of(
                    FirmaErrorCode::InvalidRequest,
                    'Recipient '.($index + 1).' does not identify one of this template\'s parties. Name it with '
                    .'`template_user_id`, or with an `order` that matches exactly one party of the template\'s '
                    .'signing sequence.',
                    [
                        'recipient_index' => $index + 1,
                        'template_user_ids' => array_values($byId),
                    ],
                );
            }

            $contacts[] = [
                'id' => $resolved,
                'name' => self::displayName($recipient),
                'email' => self::string($recipient, 'email'),
            ];
        }

        return $contacts;
    }

    /* ------------------------------------------------------------------ document */

    /**
     * @param  array<string, mixed>  $body
     *
     * @throws FirmaException
     */
    private function fromDocument(
        Workspace $workspace,
        ServiceCredential $credential,
        array $body,
        string $base64,
    ): Envelope {
        $bytes = self::decode($base64);
        $title = self::string($body, 'name') ?? 'Untitled agreement';

        $document = $this->intake->intakeBytes(
            $workspace,
            $bytes,
            $title,
            // A service credential is not a person, so the upload is attributed to the
            // credential in the audit trail rather than to a fabricated user.
            AuditActor::system('integration.firma credential '.$credential->prefix),
        );

        $revision = $document->reviewRevision();

        if (! $revision instanceof DocumentRevision) {
            throw self::preflightRefused($document);
        }

        $recipients = $this->placement->recipients(
            is_array($body['recipients'] ?? null) ? array_values($body['recipients']) : [],
        );

        $fields = is_array($body['fields'] ?? null) ? array_values($body['fields']) : [];
        $settings = is_array($body['settings'] ?? null) ? $body['settings'] : [];

        $schema = $this->placement->schema(
            documentId: $document->public_id,
            pages: $this->pages->forRevision($revision->setRelation('document', $document)),
            recipients: $recipients,
            fields: $fields,
            documentBytes: static fn (): string => $bytes,
            useSigningOrder: ($settings['use_signing_order'] ?? true) !== false,
        );

        return $this->envelopes->create(
            $workspace,
            new NewEnvelope(
                documentId: $document->public_id,
                fieldSchema: $schema,
                title: $title,
                recipients: array_map(
                    static fn (PlannedRecipient $recipient): array => $recipient->toContactOverride(),
                    $recipients,
                ),
                values: self::placedPrefills($schema, $fields),
                expiresInHours: self::expirationHours($body),
                expirySpecified: array_key_exists('expiration_hours', $body),
            ),
            $credential,
        );
    }

    /* -------------------------------------------------------------------- values */

    /**
     * Prefills from a template-based `fields[]`.
     *
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, mixed> Schema field id => value.
     *
     * @throws FirmaException
     */
    private function prefills(FieldSchemaDocument $schema, array $fields): array
    {
        $values = [];

        foreach (array_values($fields) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $value = self::suppliedValue($entry);

            if ($value === null) {
                continue;
            }

            $field = $this->locator->field(
                $schema,
                self::string($entry, 'id') ?? self::string($entry, 'template_field_id'),
                self::string($entry, 'variable_name'),
            );

            $values[$field->id] = $value;
        }

        return $values;
    }

    /**
     * Prefills from a document-based `fields[]`, matched by position in the request.
     *
     * The placement built the schema from these same entries in this same order, so entry
     * *n* owns field *n*. Matching by index rather than by variable name is what lets two
     * fields with the same name — which the recorded fixtures show — each get their own value.
     *
     * @param  array<string, mixed>  $schema
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    private static function placedPrefills(array $schema, array $fields): array
    {
        $values = [];

        foreach (array_values($fields) as $index => $entry) {
            $value = is_array($entry) ? self::suppliedValue($entry) : null;
            $id = $schema['fields'][$index]['id'] ?? null;

            if ($value !== null && is_string($id)) {
                $values[$id] = $value;
            }
        }

        return $values;
    }

    /**
     * The value an inbound field entry supplies, under any of the names upstream uses.
     *
     * `read_only_value` first: upstream describes it as the static prefill of a read-only
     * field, which is the one case where the value is definitely the sender's.
     *
     * @param  array<string, mixed>  $entry
     */
    private static function suppliedValue(array $entry): mixed
    {
        foreach (['read_only_value', 'final_value', 'value'] as $key) {
            $value = $entry[$key] ?? null;

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /* --------------------------------------------------------------------- input */

    /**
     * @throws FirmaException
     */
    private static function decode(string $base64): string
    {
        // Data URLs turn up in practice even where the contract says base64.
        $payload = preg_replace('#^data:application/pdf;base64,#i', '', trim($base64)) ?? $base64;
        $bytes = base64_decode(strtr($payload, '-_', '+/'), true);

        if ($bytes === false || $bytes === '') {
            throw FirmaException::of(
                FirmaErrorCode::InvalidRequest,
                '`document` is not valid base64.',
            );
        }

        if (strlen($bytes) > FirmaProfile::MAX_INLINE_DOCUMENT_BYTES) {
            throw FirmaException::of(
                FirmaErrorCode::InvalidRequest,
                'The inline `document` decodes to '.strlen($bytes).' bytes, over the '
                .FirmaProfile::MAX_INLINE_DOCUMENT_BYTES.'-byte limit for a document sent in a JSON body.',
            );
        }

        return $bytes;
    }

    /**
     * The preflight parser refused the document. Its findings go to the caller verbatim.
     *
     * The document row is deliberately kept, with its report — that is intake's rule, and it
     * is what an operator needs when a sender says "the system rejected our contract". No
     * envelope exists, so nothing is half-built.
     */
    private static function preflightRefused(Document $document): FirmaException
    {
        $report = is_array($document->preflight_report) ? $document->preflight_report : [];
        $findings = is_array($report['findings'] ?? null) ? $report['findings'] : [];

        return FirmaException::of(
            FirmaErrorCode::InvalidRequest,
            'The document was refused by the PDF preflight check, so no signing request was created.',
            ['findings' => array_values($findings)],
        );
    }

    /**
     * @param  array<string, mixed>  $body
     *
     * @throws FirmaException
     */
    private static function expirationHours(array $body): ?int
    {
        $hours = $body['expiration_hours'] ?? null;

        if ($hours === null) {
            return null;
        }

        if ((! is_int($hours) && ! (is_string($hours) && ctype_digit($hours))) || (int) $hours < 1) {
            throw FirmaException::of(
                FirmaErrorCode::InvalidRequest,
                '`expiration_hours` must be a whole number of hours, at least 1.',
            );
        }

        return (int) $hours;
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private static function string(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array<string, mixed>  $recipient
     */
    private static function displayName(array $recipient): ?string
    {
        $joined = trim(implode(' ', array_filter([
            trim((string) ($recipient['first_name'] ?? '')),
            trim((string) ($recipient['last_name'] ?? '')),
        ])));

        return $joined !== '' ? $joined : self::string($recipient, 'name');
    }
}
