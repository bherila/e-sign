<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Templates;

use App\Domain\Identity\Audit\AuditActor;
use App\Domain\Identity\Audit\AuditRecorder;
use App\Domain\Identity\Models\Workspace;
use App\Domain\Preparation\Anchoring\AnchorResolutionFailed;
use App\Domain\Preparation\Anchoring\AnchorResolutionOutcome;
use App\Domain\Preparation\Anchoring\RevisionAnchorResolver;
use App\Domain\Preparation\Documents\Models\Document;
use App\Domain\Preparation\Documents\Models\DocumentRevision;
use App\Domain\Preparation\Documents\PreflightPageSizes;
use App\Domain\Preparation\Schema\FieldSchemaDocument;
use App\Domain\Preparation\Schema\FieldSchemaValidator;
use App\Domain\Preparation\Schema\InvalidFieldSchemaException;
use App\Domain\Preparation\Schema\PageSizes;
use App\Domain\Preparation\Schema\Recipient;
use App\Domain\Preparation\Templates\Models\Template;
use App\Domain\Preparation\Templates\Models\TemplateAlias;
use App\Domain\Preparation\Templates\Models\TemplateVersion;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Every mutation of a template, its versions, and its aliases.
 *
 * One service so the rules live in one place: the administrative UI, the native API, and the
 * Firma facade all come through here, and none of them can invent a second lifecycle
 * (AGENTS.md, "One state machine"). The rules it enforces:
 *
 *  1. **A version snapshots a review revision, never a document.** Binding to the document
 *     would let a re-upload change what a template means; architecture invariant 2 binds an
 *     acceptance to a specific immutable revision. The document must be `ready`, because
 *     nothing may be shown for assent that preflight did not accept.
 *  2. **A published version is never edited.** An edit after publishing produces the next
 *     draft version; the published row is untouched, so envelopes already sent from it are
 *     untouched too (docs/HANDOFF.md section 6).
 *  3. **The field set is validated before it is stored, and stored canonically.** Import
 *     goes through {@see FieldSchemaDocument::fromArray()}, which fails closed with every
 *     structural error at once, and what lands in the column is
 *     {@see FieldSchemaDocument::canonicalJson()} with its digest beside it.
 *  4. **Everything is audited.** Each mutation writes one `esign_audit_events` row inside
 *     the same transaction as the change, so the trail cannot disagree with the data.
 *
 * The service does not authorize. Callers resolve the workspace inside the actor's
 * memberships and run the workspace policy first — over HTTP that is
 * App\Http\Requests\Templates\WorkspaceTemplateRequest — so an unauthorized call is a
 * missing adapter, not a missing check here.
 */
final readonly class TemplateService
{
    public function __construct(
        private FieldSchemaValidator $validator,
        private AuditRecorder $audit,
        private RevisionAnchorResolver $anchors,
        private string $defaultConsentPolicyVersion,
    ) {}

    // ------------------------------------------------------------------ templates

    public function createTemplate(
        Workspace $workspace,
        User $actor,
        string $name,
        ?string $description = null,
    ): Template {
        return DB::transaction(function () use ($workspace, $actor, $name, $description): Template {
            $template = Template::create([
                'workspace_id' => $workspace->getKey(),
                'name' => $name,
                'description' => $description,
                'created_by' => $actor->getKey(),
            ]);

            $this->audit->record(
                AuditActor::user($actor),
                'preparation.template_created',
                $template,
                [
                    'workspace_id' => $workspace->public_id,
                    'template_id' => $template->public_id,
                    'name' => $name,
                ],
            );

            return $template;
        });
    }

    /**
     * Rename or re-describe a template. Neither reaches a version, published or draft.
     *
     * Takes a change map rather than nullable arguments so "set the description to null" and
     * "leave the description alone" are different calls, which is what PATCH means.
     *
     * @param  array{name?: string, description?: string|null}  $changes
     */
    public function updateTemplate(Template $template, User $actor, array $changes): Template
    {
        return DB::transaction(function () use ($template, $actor, $changes): Template {
            $applied = [];

            if (array_key_exists('name', $changes)) {
                $template->name = $changes['name'];
                $applied['name'] = $changes['name'];
            }

            if (array_key_exists('description', $changes)) {
                $template->description = $changes['description'];
                $applied['description'] = $changes['description'];
            }

            if ($applied === []) {
                return $template;
            }

            $template->save();

            $this->audit->record(
                AuditActor::user($actor),
                'preparation.template_updated',
                $template,
                [
                    'workspace_id' => $template->workspace?->public_id,
                    'template_id' => $template->public_id,
                    'changed' => array_keys($applied),
                ],
            );

            return $template;
        });
    }

    /**
     * Retire a template from the picker, or restore it.
     *
     * Retiring deletes nothing and freezes nothing that is already out: envelopes sent from
     * this template's versions carry their own snapshot and are unaffected. What it stops is
     * drafting a new version, publishing one, and picking the template for a new envelope.
     */
    public function setRetired(Template $template, User $actor, bool $retired): Template
    {
        return DB::transaction(function () use ($template, $actor, $retired): Template {
            if ($template->isRetired() === $retired) {
                return $template;
            }

            $template->retired_at = $retired ? now() : null;
            $template->save();

            $this->audit->record(
                AuditActor::user($actor),
                $retired ? 'preparation.template_retired' : 'preparation.template_restored',
                $template,
                [
                    'workspace_id' => $template->workspace?->public_id,
                    'template_id' => $template->public_id,
                ],
            );

            return $template;
        });
    }

    // ------------------------------------------------------------------- versions

    /**
     * Draft the next version of a template from a document's review revision.
     *
     * This is also how a template is edited after a publish: there is no path that mutates a
     * published version, so "change the fields and send again" means drafting version n+1
     * and publishing that.
     *
     * @param  array<string, mixed>  $fieldSchema  A native field schema document (version 1.0).
     * @param  array<string, mixed>|null  $renderSettings  Null takes {@see RenderSettings::defaults()}.
     *
     * @throws TemplateStateException When the template is retired, the document belongs to
     *                                another workspace, or the document has no review
     *                                revision to snapshot.
     * @throws InvalidFieldSchemaException With every structural error in the field set.
     * @throws InvalidArgumentException On an unknown or unsupported render setting.
     */
    public function createDraftVersion(
        Template $template,
        User $actor,
        Document $document,
        array $fieldSchema,
        ?string $consentPolicyVersion = null,
        ?array $renderSettings = null,
    ): TemplateVersion {
        if ($template->isRetired()) {
            throw TemplateStateException::templateRetired($template);
        }

        $revision = $this->reviewRevisionOf($template, $document);
        $schema = $this->importSchema($fieldSchema, $document);
        $settings = $renderSettings === null ? RenderSettings::defaults() : RenderSettings::fromArray($renderSettings);
        $consent = $this->consentPolicyVersion($consentPolicyVersion);
        $canonical = $schema->canonicalJson();

        return DB::transaction(function () use (
            $template,
            $actor,
            $document,
            $revision,
            $schema,
            $settings,
            $consent,
            $canonical,
        ): TemplateVersion {
            // Read the counter inside the transaction; the unique key on
            // (template_id, version) is what makes a lost race a database error rather than
            // two versions quietly sharing a number.
            $next = 1 + (int) TemplateVersion::query()
                ->where('template_id', $template->getKey())
                ->lockForUpdate()
                ->max('version');

            $version = TemplateVersion::create([
                'template_id' => $template->getKey(),
                'version' => $next,
                'document_revision_id' => $revision->getKey(),
                'field_schema' => $schema->toArray(),
                'field_schema_sha256' => hash('sha256', $canonical),
                'recipients' => $this->recipientsOf($schema),
                'consent_policy_version' => $consent,
                'render_settings' => $settings->toArray(),
                'created_by' => $actor->getKey(),
            ]);

            $this->audit->record(
                AuditActor::user($actor),
                'preparation.template_version_drafted',
                $version,
                [
                    'workspace_id' => $template->workspace?->public_id,
                    'template_id' => $template->public_id,
                    'template_version_id' => $version->public_id,
                    'version' => $next,
                    'document_id' => $document->public_id,
                    'document_revision_id' => $revision->public_id,
                    'document_revision_sha256' => $revision->sha256,
                    'field_schema_sha256' => $version->field_schema_sha256,
                    'consent_policy_version' => $consent,
                ],
            );

            return $version;
        });
    }

    /**
     * Edit a draft version in place.
     *
     * Only a draft: a published version is refused here as well as by the model, so the
     * caller gets a message that says what to do instead of a write that throws halfway
     * through a transaction.
     *
     * @param  array<string, mixed>|null  $fieldSchema  Null leaves the stored field set alone.
     * @param  array<string, mixed>|null  $renderSettings
     *
     * @throws PublishedVersionIsImmutableException When the version is published.
     * @throws InvalidFieldSchemaException With every structural error in the field set.
     */
    public function updateDraftVersion(
        TemplateVersion $version,
        User $actor,
        ?array $fieldSchema = null,
        ?string $consentPolicyVersion = null,
        ?array $renderSettings = null,
    ): TemplateVersion {
        if ($version->isPublished()) {
            throw new PublishedVersionIsImmutableException($version->public_id);
        }

        $template = $version->template;
        $schema = null;

        if ($fieldSchema !== null) {
            $schema = $this->importSchema($fieldSchema, $version->documentRevision?->document);
        }

        $settings = $renderSettings === null ? null : RenderSettings::fromArray($renderSettings);

        return DB::transaction(function () use (
            $version,
            $actor,
            $template,
            $schema,
            $consentPolicyVersion,
            $settings,
        ): TemplateVersion {
            $changed = [];

            if ($schema instanceof FieldSchemaDocument) {
                $version->field_schema = $schema->toArray();
                $version->field_schema_sha256 = hash('sha256', $schema->canonicalJson());
                $version->recipients = $this->recipientsOf($schema);
                $changed[] = 'field_schema';
            }

            if ($consentPolicyVersion !== null) {
                $version->consent_policy_version = $consentPolicyVersion;
                $changed[] = 'consent_policy_version';
            }

            if ($settings instanceof RenderSettings) {
                $version->render_settings = $settings->toArray();
                $changed[] = 'render_settings';
            }

            if ($changed === []) {
                return $version;
            }

            $version->save();

            $this->audit->record(
                AuditActor::user($actor),
                'preparation.template_version_updated',
                $version,
                [
                    'workspace_id' => $template?->workspace?->public_id,
                    'template_id' => $template?->public_id,
                    'template_version_id' => $version->public_id,
                    'version' => $version->version,
                    'changed' => $changed,
                    'field_schema_sha256' => $version->field_schema_sha256,
                ],
            );

            return $version;
        });
    }

    /**
     * Publish a draft: resolve its anchors, then lock it forever and make it the template's
     * current version.
     *
     * The row is re-read `FOR UPDATE` inside the transaction and re-checked before the
     * stamp, so two concurrent publishes produce one published version and one
     * `version_already_published` error rather than two audit events and an ambiguous
     * `current_version_id` (architecture invariant 6).
     *
     * ## Why anchors resolve here
     *
     * Publishing is the last moment a template can be fixed cheaply, and it is the first moment
     * a field set and the exact bytes it will be placed on are both fixed: a version snapshots a
     * specific immutable review revision, and publishing freezes the field set against it. So an
     * anchor whose text is not in that document, or is in it twice, is discoverable *now* — while
     * the sender is still authoring — instead of when they try to send.
     *
     * It is not the authoritative resolution. Send is, because an envelope does not have to come
     * from a template at all. What publishing buys is the early failure and a published version
     * whose rectangles are already concrete, so every envelope drawn from it starts with the
     * placement settled and re-resolves nothing.
     *
     * An *optional* anchor that is absent is reported here and left unresolved rather than
     * omitted: which fields an envelope leaves out is a fact about that envelope, and it is
     * recorded on the envelope when it is sent.
     *
     * @throws TemplateStateException When the template is retired or the version is already published.
     * @throws InvalidFieldSchemaException When an anchor cannot be resolved, with one entry per
     *                                     unplaceable field and a JSON Pointer to each.
     */
    public function publish(TemplateVersion $version, User $actor): TemplateVersion
    {
        $template = $version->template;

        if ($template instanceof Template && $template->isRetired()) {
            throw TemplateStateException::templateRetired($template);
        }

        // Outside the transaction, for the reason EnvelopeStateMachine::send() gives: resolving
        // means reading a document object and parsing its content streams, and doing that under
        // `lockForUpdate()` would hold the template-version row for the length of a PDF parse.
        // It is safe out here because the result is confirmed against the locked row below, and
        // a draft nobody else is editing will simply match.
        $prepared = $this->resolveAnchors($version);

        return DB::transaction(function () use ($version, $actor, $template, $prepared): TemplateVersion {
            /** @var TemplateVersion $locked */
            $locked = TemplateVersion::query()
                ->whereKey($version->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->isPublished()) {
                throw TemplateStateException::versionAlreadyPublished($locked->public_id);
            }

            $resolution = $this->resolutionFor($locked, $prepared);

            if ($resolution->changed()) {
                $schema = $resolution->schema;
                $locked->field_schema = $schema->toArray();
                $locked->field_schema_sha256 = hash('sha256', $schema->canonicalJson());
            }

            $locked->published_at = now();
            $locked->save();

            if ($template instanceof Template) {
                $template->current_version_id = $locked->getKey();
                $template->save();
            }

            $this->audit->record(
                AuditActor::user($actor),
                'preparation.template_version_published',
                $locked,
                [
                    'workspace_id' => $template?->workspace?->public_id,
                    'template_id' => $template?->public_id,
                    'template_version_id' => $locked->public_id,
                    'version' => $locked->version,
                    'document_revision_id' => $locked->documentRevision?->public_id,
                    'field_schema_sha256' => $locked->field_schema_sha256,
                    'consent_policy_version' => $locked->consent_policy_version,
                    'anchors_resolved' => count($resolution->resolved),
                    'anchor_fields_absent' => $resolution->omissionsToArray(),
                ],
            );

            if ($resolution->resolved !== [] || $resolution->omissions !== []) {
                $this->audit->record(
                    AuditActor::user($actor),
                    'preparation.template_version_anchors_resolved',
                    $locked,
                    [
                        'workspace_id' => $template?->workspace?->public_id,
                        'template_id' => $template?->public_id,
                        'template_version_id' => $locked->public_id,
                        'document_revision_id' => $locked->documentRevision?->public_id,
                    ] + $resolution->toAuditPayload(),
                );
            }

            return $locked;
        });
    }

    // -------------------------------------------------------------------- aliases

    /**
     * Map a foreign identifier onto this template.
     *
     * The pre-check gives a usable message; the caught unique violation is what actually
     * makes it safe, because between a check and an insert another request can take the
     * alias. Uniqueness is per workspace on purpose: two tenants migrating from the same
     * provider will legitimately present the same provider template id.
     *
     * @throws TemplateStateException When the alias is already mapped in this workspace.
     */
    public function addAlias(
        Template $template,
        User $actor,
        string $alias,
        TemplateAliasSource $source,
    ): TemplateAlias {
        $alias = trim($alias);

        if ($alias === '') {
            throw new InvalidArgumentException('A template alias cannot be empty.');
        }

        $taken = TemplateAlias::query()
            ->where('workspace_id', $template->workspace_id)
            ->where('alias', $alias)
            ->exists();

        if ($taken) {
            throw TemplateStateException::aliasAlreadyTaken($alias);
        }

        return DB::transaction(function () use ($template, $actor, $alias, $source): TemplateAlias {
            try {
                $record = TemplateAlias::create([
                    'template_id' => $template->getKey(),
                    'workspace_id' => $template->workspace_id,
                    'alias' => $alias,
                    'source' => $source->value,
                ]);
            } catch (QueryException $e) {
                if ($this->isUniqueViolation($e)) {
                    throw TemplateStateException::aliasAlreadyTaken($alias);
                }

                throw $e;
            }

            $this->audit->record(
                AuditActor::user($actor),
                'preparation.template_alias_added',
                $template,
                [
                    'workspace_id' => $template->workspace?->public_id,
                    'template_id' => $template->public_id,
                    'alias' => $alias,
                    'source' => $source->value,
                ],
            );

            return $record;
        });
    }

    /** @throws TemplateStateException When this template has no such alias. */
    public function removeAlias(Template $template, User $actor, string $alias): void
    {
        $alias = trim($alias);

        DB::transaction(function () use ($template, $actor, $alias): void {
            $record = TemplateAlias::query()
                ->where('template_id', $template->getKey())
                ->where('alias', $alias)
                ->first();

            if (! $record instanceof TemplateAlias) {
                throw TemplateStateException::aliasNotFound($alias);
            }

            $source = $record->source;
            $record->delete();

            $this->audit->record(
                AuditActor::user($actor),
                'preparation.template_alias_removed',
                $template,
                [
                    'workspace_id' => $template->workspace?->public_id,
                    'template_id' => $template->public_id,
                    'alias' => $alias,
                    'source' => $source->value,
                ],
            );
        });
    }

    /**
     * Resolve a foreign identifier to a template, within one workspace.
     *
     * Scoped to the workspace, so an alias another tenant registered does not resolve at all
     * rather than resolving and then being forbidden — the same property
     * `Workspace::whereMemberOf()` gives the rest of the module.
     */
    public function resolveAlias(Workspace $workspace, string $alias): ?Template
    {
        $record = TemplateAlias::query()
            ->inWorkspace($workspace)
            ->where('alias', trim($alias))
            ->first();

        return $record?->template;
    }

    // -------------------------------------------------------------------- internals

    /**
     * The review revision a version will snapshot, with every reason it might not exist
     * reported as a state error rather than a null.
     */
    private function reviewRevisionOf(Template $template, Document $document): DocumentRevision
    {
        if ($document->workspace_id !== $template->workspace_id) {
            throw TemplateStateException::documentInAnotherWorkspace($document);
        }

        if (! $document->isReady()) {
            throw TemplateStateException::documentNotReady($document);
        }

        $revision = $document->reviewRevision();

        if (! $revision instanceof DocumentRevision) {
            throw TemplateStateException::documentHasNoReviewRevision($document);
        }

        return $revision;
    }

    /**
     * Validate and canonicalise a field set.
     *
     * Page sizes come from the document's own preflight report when there is one, so a
     * rectangle off the edge of a page is caught here rather than at send time. The prefill
     * variable check is deliberately not run: the variable set belongs to the sending
     * context, which a template does not have, and an omitted check is never reported as a
     * passed one (docs/preparation/field-schema.md).
     *
     * The field set's own `document_id` is preserved verbatim and is *not* required to equal
     * the document it is stored against: schemas arrive from imports that use the provider's
     * identifier. What binds a version to bytes is `document_revision_id`, never this value.
     *
     * @param  array<string, mixed>  $fieldSchema
     *
     * @throws InvalidFieldSchemaException
     */
    private function importSchema(array $fieldSchema, ?Document $document): FieldSchemaDocument
    {
        $result = $this->validator->validate($fieldSchema, $this->pageSizesFor($document));

        if ($result->hasErrors()) {
            throw new InvalidFieldSchemaException($result);
        }

        return FieldSchemaDocument::fromArray($fieldSchema);
    }

    /**
     * The resolution to store, confirmed against the row actually holding the lock.
     *
     * {@see publish()} resolves before opening the transaction so a PDF parse does not happen
     * under the row lock. A draft can still be edited between the two, so the digest of the field
     * set the pass ran against is compared with the locked row's, and a disagreement resolves
     * again — inside the lock this time, because correctness outranks the lock-duration saving
     * and a racing edit is rare enough that paying for it twice costs nothing in practice.
     *
     * @throws InvalidFieldSchemaException
     */
    private function resolutionFor(TemplateVersion $locked, AnchorResolutionOutcome $prepared): AnchorResolutionOutcome
    {
        if (hash_equals((string) $locked->field_schema_sha256, $prepared->sourceSchemaSha256)) {
            return $prepared;
        }

        return $this->resolveAnchors($locked);
    }

    /**
     * Resolve the version's anchors against the revision it snapshots.
     *
     * Absent optional anchors are reported, not applied: see {@see publish()}. A failure becomes
     * an {@see InvalidFieldSchemaException} so publishing answers exactly like drafting does — a
     * 422 carrying every problem at once, each with a stable code and a JSON Pointer to the
     * offending field, which is what lets the editor put the message on the field instead of on
     * the document.
     *
     * @throws InvalidFieldSchemaException
     */
    private function resolveAnchors(TemplateVersion $version): AnchorResolutionOutcome
    {
        $schema = $version->fieldSchemaDocument();
        $revision = $version->documentRevision;

        if (! $revision instanceof DocumentRevision || $schema->anchoredFields() === []) {
            return AnchorResolutionOutcome::unchanged($schema);
        }

        try {
            return $this->anchors->resolve($revision, $schema, omitAbsentFields: false);
        } catch (AnchorResolutionFailed $failed) {
            throw new InvalidFieldSchemaException($failed->toValidationResult());
        }
    }

    /**
     * Displayed page sizes from a document's preflight report, or null when the report does
     * not carry usable geometry (a document that never parsed, or a factory-made row).
     *
     * A report this application cannot read is not a reason to refuse a draft; it means the
     * page-fit check is not performed, which the validator's messages say.
     */
    private function pageSizesFor(?Document $document): ?PageSizes
    {
        return PreflightPageSizes::of($document);
    }

    /**
     * The recipients/roles list, denormalised out of the field set.
     *
     * Written from the schema and never independently, so the column and the schema cannot
     * disagree. `stage` is the 1-based signing stage from `signing_order`, so the Signing
     * module can read who signs when without parsing the whole field set.
     *
     * @return list<array{id: string, name: string, email: string, role: string|null, stage: int|null}>
     */
    private function recipientsOf(FieldSchemaDocument $schema): array
    {
        return array_map(
            static fn (Recipient $recipient): array => [
                'id' => $recipient->id,
                'name' => $recipient->name,
                'email' => $recipient->email,
                'role' => $recipient->role,
                'stage' => $schema->stageOf($recipient->id),
            ],
            $schema->recipients,
        );
    }

    private function consentPolicyVersion(?string $requested): string
    {
        $requested = $requested === null ? '' : trim($requested);

        return $requested === '' ? $this->defaultConsentPolicyVersion : $requested;
    }

    /** SQLite, MySQL, and MariaDB all report a unique violation as SQLSTATE 23000. */
    private function isUniqueViolation(QueryException $e): bool
    {
        return in_array($e->getCode(), ['23000', '23505'], true);
    }
}
