<?php

declare(strict_types=1);

namespace App\Domain\Integration\Firma;

use App\Domain\Identity\Audit\AuditActor;
use App\Domain\Identity\Credentials\CurrentPrincipal;
use App\Domain\Identity\Credentials\MissingScope;
use App\Domain\Identity\Credentials\Scope;
use App\Domain\Identity\Credentials\ServiceCredential;
use App\Domain\Identity\Models\Workspace;
use App\Domain\Integration\Native\EnvelopeService;
use App\Domain\Integration\Native\NewEnvelope;
use App\Domain\Preparation\Documents\DocumentIntake;
use App\Domain\Preparation\Documents\DocumentStorageException;
use App\Domain\Preparation\Documents\Models\Document;
use App\Domain\Preparation\Documents\Models\DocumentRevision;
use App\Domain\Preparation\Documents\RevisionBytes;
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
 *
 * ## What "rolled back" does and does not cover
 *
 * **No signing request is ever half-created.** Creation is one transaction, and everything
 * that can be judged without the document — an unsupported option, an out-of-range
 * percentage, an unplaceable field type, a recipient with no `order`, an approver — is
 * refused before the bytes are stored, so those requests leave nothing at all.
 *
 * **A stored document is not rolled back**, and deliberately so. It is intake's own rule
 * that an upload is kept with its report, because that report is the only evidence of what
 * was uploaded when a sender says "the system rejected our contract". So a request refused
 * for a reason that genuinely needed the document — a page it does not have, an anchor whose
 * text is not in it — leaves the document behind, and a caller who fixes the request and
 * retries uploads it again. Nothing references the first one; `docs/BLOB_STORAGE.md`'s pruner
 * is what reclaims unreferenced objects.
 */
final readonly class SigningRequestCreation
{
    public function __construct(
        private EnvelopeService $envelopes,
        private SigningRequestLocator $locator,
        private FieldPlacement $placement,
        private PageGeometryReader $pages,
        private DocumentIntake $intake,
        private CurrentPrincipal $principal,
        private RevisionBytes $bytes,
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
        $this->requireTemplateScope();

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
                requireOtp: self::requireOtp($body),
            ),
            $credential,
        );
    }

    /**
     * Reading a template is a distinct authority from creating an agreement.
     *
     * Enforced here rather than only on the route, because whether a create *uses* a template
     * is a property of the body and not of the endpoint: both `POST /signing-requests` and
     * `POST /signing-requests/create-and-send` accept either `template_id` or an inline
     * `document`. Naming `templates:read` on one route and not the other left the gate
     * bypassable — a credential without it could build the same agreement from the same
     * template through the other route and then call `/send`. Naming it on both would have
     * demanded a template scope of a caller that only ever posts its own PDFs.
     *
     * `routes/compat-firma.php` points at this method so the authority is still findable from
     * the route definitions.
     *
     * @throws FirmaException
     */
    private function requireTemplateScope(): void
    {
        try {
            $this->principal->requireScope(Scope::TemplatesRead);
        } catch (MissingScope) {
            throw FirmaException::of(
                FirmaErrorCode::Forbidden,
                "This API key is not granted '".Scope::TemplatesRead->value."', which building a signing "
                .'request from a template requires. Supply `document` instead, or have the credential '
                .'reissued with that scope.',
                ['required_scope' => Scope::TemplatesRead->value],
            );
        }
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

    /**
     * The review revision's stored bytes.
     *
     * @throws FirmaException When the object is gone, which is an internal failure rather than
     *                        anything the caller can fix — and says so without naming a path.
     */
    private function reviewBytes(DocumentRevision $revision): string
    {
        try {
            // Verified against the row's digest, because the receipt stamped below asserts that
            // digest. Bytes that do not hash to it are not this revision, and measuring anchors
            // in them would invite signers against a document the envelope is not bound to.
            return $this->bytes->read($revision);
        } catch (DocumentStorageException $failure) {
            report($failure);

            throw FirmaException::of(
                FirmaErrorCode::InternalError,
                'The document behind this signing request could not be read, so no anchor in it '
                .'can be resolved.',
            );
        }
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

        $recipients = $this->placement->recipients(
            is_array($body['recipients'] ?? null) ? array_values($body['recipients']) : [],
        );

        $fields = is_array($body['fields'] ?? null) ? array_values($body['fields']) : [];

        // Everything that can be judged without the document, judged before it is stored.
        // A percentage outside 0..100, an unplaceable field type, an approver, a recipient
        // with no order — none of those need a page size, so none of them should leave an
        // uploaded document behind attached to a signing request that was never created.
        $this->placement->validateWithoutDocument($fields);

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

        $settings = is_array($body['settings'] ?? null) ? $body['settings'] : [];

        $revision->setRelation('document', $document);

        $schema = $this->placement->schema(
            documentId: $document->public_id,
            pages: $this->pages->forRevision($revision),
            recipients: $recipients,
            fields: $fields,
            // The *review revision's* bytes, not the upload's. Normalization may rebuild the
            // pages (`esign.documents.normalization.rebuild_pages`), and then the two are
            // different PDFs: the page geometry above already comes from the revision, and text
            // read from the upload would be measured against a page it does not belong to. It is
            // also the document that will be shown for assent and stamped at finalization, so it
            // is the only one an anchor may be resolved in.
            documentBytes: fn (): string => $this->reviewBytes($revision),
            useSigningOrder: ($settings['use_signing_order'] ?? true) !== false,
            // The digest the anchors were resolved against, so the envelope's own send-time
            // resolution can see the work is already done for these exact bytes. It is the same
            // revision the closure above reads, which is what makes the receipt true.
            documentSha256: (string) $revision->sha256,
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
                requireOtp: self::requireOtp($body),
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
     * `settings.require_otp_verification`, honoured rather than echoed.
     *
     * Null means the caller said nothing, and that is **not** false: the envelope then
     * inherits its workspace's setting and the deployment default
     * (App\Domain\Signing\Sessions\OtpRequirement). An explicit `false` overrules both,
     * which a caller migrating from a workspace that required a code needs to be able to say.
     *
     * `GET /signing-requests/{id}` reports the *resolved* value, so a caller that set nothing
     * still sees what its guests will be asked for.
     *
     * @param  array<string, mixed>  $body
     */
    private static function requireOtp(array $body): ?bool
    {
        $settings = is_array($body['settings'] ?? null) ? $body['settings'] : [];
        $value = $settings['require_otp_verification'] ?? null;

        return is_bool($value) ? $value : null;
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
