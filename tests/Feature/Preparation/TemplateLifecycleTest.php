<?php

declare(strict_types=1);

namespace Tests\Feature\Preparation;

use App\Domain\Identity\Audit\AuditEvent;
use App\Domain\Identity\Enums\WorkspaceRole;
use App\Domain\Identity\Models\Workspace;
use App\Domain\Preparation\Documents\DocumentIntake;
use App\Domain\Preparation\Documents\DocumentStatus;
use App\Domain\Preparation\Documents\Models\Document;
use App\Domain\Preparation\Schema\AnchorPlacementMode;
use App\Domain\Preparation\Schema\FieldSchemaDocument;
use App\Domain\Preparation\Schema\InvalidFieldSchemaException;
use App\Domain\Preparation\Templates\Models\Template;
use App\Domain\Preparation\Templates\Models\TemplateVersion;
use App\Domain\Preparation\Templates\PublishedVersionIsImmutableException;
use App\Domain\Preparation\Templates\RenderSettings;
use App\Domain\Preparation\Templates\TemplateAliasSource;
use App\Domain\Preparation\Templates\TemplateService;
use App\Domain\Preparation\Templates\TemplateStateException;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\DocumentWorkspace;
use Tests\Support\FieldSchemaFixture;
use Tests\TestCase;

/**
 * The template lifecycle, at the domain service.
 *
 * What this file is really asserting is one sentence from docs/HANDOFF.md section 6: "Later
 * template changes never mutate existing requests." Everything below is a way for that to
 * be false — editing a published version, publishing twice, republishing over a live
 * `current_version_id`, snapshotting a draft that can still move — and each one is closed
 * here rather than left to the caller.
 *
 * Documents go through the real DocumentIntake with committed synthetic fixtures rather than
 * DocumentFactory, because a version binds to a *review revision* and a factory-made row has
 * none. `multi-page-mixed-size` is the fixture used for the happy path: the shared
 * `nda-two-signers` field set places fields on pages 1 and 2, and the page-fit check runs
 * against the geometry in that document's own preflight report.
 */
class TemplateLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $sender;

    private TemplateService $templates;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');

        $this->workspace = Workspace::factory()->create();
        $this->sender = DocumentWorkspace::memberOf($this->workspace, WorkspaceRole::Sender);
        $this->templates = app(TemplateService::class);
    }

    protected function tearDown(): void
    {
        DocumentWorkspace::cleanUp();

        parent::tearDown();
    }

    // ---------------------------------------------------------------- lifecycle

    public function test_a_template_starts_with_no_versions_and_nothing_current(): void
    {
        $template = $this->templates->createTemplate(
            $this->workspace,
            $this->sender,
            'Mutual NDA',
            'Two-signer mutual non-disclosure agreement.',
        );

        $this->assertSame('Mutual NDA', $template->name);
        $this->assertSame($this->workspace->getKey(), $template->workspace_id);
        $this->assertNull($template->current_version_id);
        $this->assertNull($template->currentVersion());
        $this->assertFalse($template->isRetired());
        $this->assertSame(0, $template->highestVersionNumber());

        $this->assertDatabaseHas('esign_audit_events', [
            'action' => 'preparation.template_created',
            'subject_id' => (string) $template->getKey(),
        ]);
    }

    public function test_the_full_lifecycle_draft_publish_edit_publish_retire(): void
    {
        $document = $this->readyDocument();
        $template = $this->newTemplate();

        $first = $this->templates->createDraftVersion(
            $template,
            $this->sender,
            $document,
            FieldSchemaFixture::asArray(),
        );

        $this->assertSame(1, $first->version);
        $this->assertTrue($first->isDraft());
        // A template with only drafts is deliberately not sendable.
        $this->assertNull($template->fresh()?->current_version_id);

        $this->templates->publish($first, $this->sender);
        $template->refresh();
        $first->refresh();

        $this->assertTrue($first->isPublished());
        $this->assertSame($first->getKey(), $template->current_version_id);
        $this->assertSame($first->getKey(), $template->currentVersion()?->getKey());

        // An edit after publishing is the next version, never a change to this one.
        $second = $this->templates->createDraftVersion(
            $template,
            $this->sender,
            $document,
            $this->schemaWithout('counterparty_notes'),
        );

        $this->assertSame(2, $second->version);
        $this->assertSame($first->getKey(), $template->fresh()?->current_version_id);

        $this->templates->publish($second, $this->sender);
        $template->refresh();

        $this->assertSame($second->getKey(), $template->current_version_id);
        $this->assertSame(2, $template->currentVersion()?->version);

        $this->templates->setRetired($template, $this->sender, true);
        $this->assertTrue($template->fresh()?->isRetired());

        // Retiring changes nothing about the published versions themselves.
        $this->assertTrue($first->fresh()?->isPublished());
        $this->assertTrue($second->fresh()?->isPublished());
    }

    public function test_the_version_counter_is_per_template(): void
    {
        $document = $this->readyDocument();
        $one = $this->newTemplate('Order form');
        $two = $this->newTemplate('Destruction certificate');

        $this->assertSame(1, $this->templates->createDraftVersion($one, $this->sender, $document, FieldSchemaFixture::asArray())->version);
        $this->assertSame(2, $this->templates->createDraftVersion($one, $this->sender, $document, FieldSchemaFixture::asArray())->version);
        $this->assertSame(1, $this->templates->createDraftVersion($two, $this->sender, $document, FieldSchemaFixture::asArray())->version);
    }

    // ------------------------------------------------------------ immutability

    public function test_publishing_locks_the_version_at_the_model(): void
    {
        $version = $this->publishedVersion();

        $version->consent_policy_version = 'rewritten';

        try {
            $version->save();
            $this->fail('A published template version accepted an update.');
        } catch (PublishedVersionIsImmutableException $e) {
            $this->assertSame($version->public_id, $e->templateVersionId);
        }

        try {
            $version->fresh()?->delete();
            $this->fail('A published template version accepted a delete.');
        } catch (PublishedVersionIsImmutableException) {
            // Expected.
        }

        $this->assertDatabaseHas('template_versions', [
            'id' => $version->getKey(),
            'consent_policy_version' => config('esign.templates.default_consent_policy_version'),
        ]);
    }

    public function test_editing_a_published_version_through_the_service_raises(): void
    {
        $version = $this->publishedVersion();
        $before = $version->field_schema_sha256;

        $this->expectException(PublishedVersionIsImmutableException::class);

        try {
            $this->templates->updateDraftVersion(
                $version,
                $this->sender,
                $this->schemaWithout('counterparty_notes'),
            );
        } finally {
            $this->assertSame($before, $version->fresh()?->field_schema_sha256);
        }
    }

    public function test_a_draft_can_be_edited_in_place_and_a_published_version_cannot(): void
    {
        $document = $this->readyDocument();
        $template = $this->newTemplate();
        $draft = $this->templates->createDraftVersion($template, $this->sender, $document, FieldSchemaFixture::asArray());
        $before = $draft->field_schema_sha256;

        $this->templates->updateDraftVersion(
            $draft,
            $this->sender,
            $this->schemaWithout('counterparty_notes'),
            'consent-2027-01-01',
            ['date_format' => 'long', 'include_certificate_page' => false],
        );

        $draft->refresh();

        $this->assertSame(1, $draft->version, 'Editing a draft edits it; it does not create a version.');
        $this->assertNotSame($before, $draft->field_schema_sha256);
        $this->assertSame('consent-2027-01-01', $draft->consent_policy_version);
        $this->assertSame('long', $draft->renderSettings()->dateFormat);
        $this->assertFalse($draft->renderSettings()->includeCertificatePage);
        $this->assertNull($draft->fieldSchemaDocument()->field('counterparty_notes'));
        $this->assertDatabaseCount('template_versions', 1);
    }

    public function test_publishing_twice_is_refused(): void
    {
        $version = $this->publishedVersion();

        try {
            $this->templates->publish($version->fresh() ?? $version, $this->sender);
            $this->fail('A version was published twice.');
        } catch (TemplateStateException $e) {
            $this->assertSame('version_already_published', $e->reason);
        }

        $this->assertSame(
            1,
            (int) TemplateVersion::query()->whereNotNull('published_at')->count(),
        );
    }

    // ---------------------------------------------------------------- snapshot

    public function test_the_snapshot_carries_everything_an_envelope_copies(): void
    {
        $document = $this->readyDocument();
        $revision = $document->reviewRevision();
        $this->assertNotNull($revision);

        $template = $this->newTemplate('Mutual NDA');
        $version = $this->templates->createDraftVersion(
            $template,
            $this->sender,
            $document,
            FieldSchemaFixture::asArray(),
            'consent-2026-09-01',
            ['date_format' => 'us', 'timezone' => 'America/New_York'],
        );
        $this->templates->publish($version, $this->sender);

        $snapshot = ($version->fresh(['template', 'documentRevision.document']) ?? $version)->snapshotForEnvelope();

        $this->assertSame([
            'template_id',
            'template_name',
            'template_version_id',
            'version',
            'document_id',
            'document_revision_id',
            'document_revision_public_id',
            'document_revision_sha256',
            'document_page_count',
            'field_schema',
            'field_schema_sha256',
            'recipients',
            'consent_policy_version',
            'render_settings',
            'published_at',
        ], array_keys($snapshot), 'The snapshot shape is what the Signing module copies; adding a key is a deliberate change.');

        $this->assertSame($template->public_id, $snapshot['template_id']);
        $this->assertSame('Mutual NDA', $snapshot['template_name']);
        $this->assertSame(1, $snapshot['version']);
        $this->assertSame($document->public_id, $snapshot['document_id']);
        $this->assertSame($revision->getKey(), $snapshot['document_revision_id']);
        $this->assertSame($revision->public_id, $snapshot['document_revision_public_id']);
        $this->assertSame($revision->sha256, $snapshot['document_revision_sha256']);
        $this->assertSame('consent-2026-09-01', $snapshot['consent_policy_version']);
        $this->assertSame('us', $snapshot['render_settings']['date_format']);
        $this->assertSame('America/New_York', $snapshot['render_settings']['timezone']);
        $this->assertNotNull($snapshot['published_at']);

        // The field set is the canonical document, and the digest is the digest of it.
        $this->assertSame(
            FieldSchemaDocument::fromArray(FieldSchemaFixture::asArray())->canonicalJson(),
            json_encode($snapshot['field_schema'], FieldSchemaDocument::JSON_FLAGS),
        );
        $this->assertSame(
            hash('sha256', FieldSchemaDocument::fromArray(FieldSchemaFixture::asArray())->canonicalJson()),
            $snapshot['field_schema_sha256'],
        );

        // No storage handle reaches a snapshot, any more than it reaches a response.
        $encoded = (string) json_encode($snapshot);
        $this->assertStringNotContainsString($revision->path, $encoded);
        $this->assertStringNotContainsString('"disk"', $encoded);
        $this->assertStringNotContainsString('"path"', $encoded);
    }

    public function test_a_draft_cannot_be_snapshotted_because_it_can_still_change(): void
    {
        $document = $this->readyDocument();
        $draft = $this->templates->createDraftVersion(
            $this->newTemplate(),
            $this->sender,
            $document,
            FieldSchemaFixture::asArray(),
        );

        try {
            $draft->snapshotForEnvelope();
            $this->fail('A draft version produced an envelope snapshot.');
        } catch (TemplateStateException $e) {
            $this->assertSame('version_not_published', $e->reason);
        }
    }

    public function test_recipients_are_denormalised_from_the_schema_with_their_signing_stage(): void
    {
        $document = $this->readyDocument();
        $version = $this->templates->createDraftVersion(
            $this->newTemplate(),
            $this->sender,
            $document,
            FieldSchemaFixture::asArray(),
        );

        $this->assertSame([
            ['id' => 'buyer', 'name' => 'Example Buyer', 'email' => 'buyer@example.test', 'role' => 'Buyer', 'stage' => 1],
            ['id' => 'counterparty', 'name' => 'Example Counterparty', 'email' => 'counterparty@example.test', 'role' => 'Counterparty', 'stage' => 2],
        ], $version->recipients);
    }

    public function test_the_stored_field_schema_is_canonical_and_hashes_to_the_recorded_digest(): void
    {
        $document = $this->readyDocument();

        // A document that merely validates: defaults omitted and an integral coordinate spelled
        // as a float. Storage canonicalises the *spelling* once, and the digest is of that. It
        // does not canonicalise precision — a coordinate finer than a thousandth is refused
        // rather than rounded, because rounding is a transformation the two implementations of
        // this schema do not agree about.
        $schema = FieldSchemaFixture::asArray();
        unset($schema['fields'][0]['required'], $schema['fields'][0]['read_only']);
        $schema['fields'][0]['rect']['x'] = 60.0;

        $version = $this->templates->createDraftVersion(
            $this->newTemplate(),
            $this->sender,
            $document,
            $schema,
        );

        $version->refresh();

        $this->assertTrue($version->fieldSchemaDigestMatches());
        $this->assertSame(
            $version->field_schema_sha256,
            hash('sha256', $version->canonicalFieldSchemaJson()),
        );
        $this->assertTrue($version->fieldSchemaDocument()->fields[0]->required);
        $this->assertSame(60.0, $version->fieldSchemaDocument()->fields[0]->rect->x);
        $this->assertStringContainsString('"x":60,', $version->canonicalFieldSchemaJson());

        // Idempotent: re-canonicalising the stored form changes nothing.
        $this->assertSame(
            $version->canonicalFieldSchemaJson(),
            FieldSchemaDocument::fromJson($version->canonicalFieldSchemaJson())->canonicalJson(),
        );
    }

    public function test_the_default_render_settings_and_consent_version_come_from_configuration(): void
    {
        $document = $this->readyDocument();
        $version = $this->templates->createDraftVersion(
            $this->newTemplate(),
            $this->sender,
            $document,
            FieldSchemaFixture::asArray(),
        );

        $this->assertSame(
            (string) config('esign.templates.default_consent_policy_version'),
            $version->consent_policy_version,
        );
        $this->assertSame(RenderSettings::defaults()->toArray(), $version->render_settings);
    }

    // -------------------------------------------------------------- rejections

    public function test_an_invalid_field_schema_is_rejected_with_every_structured_error(): void
    {
        $document = $this->readyDocument();
        $schema = FieldSchemaFixture::asArray();
        $schema['fields'][0]['recipient_id'] = 'nobody';
        $schema['fields'][1]['rect']['width'] = 0;

        try {
            $this->templates->createDraftVersion(
                $this->newTemplate(),
                $this->sender,
                $document,
                $schema,
            );
            $this->fail('An invalid field schema was stored.');
        } catch (InvalidFieldSchemaException $e) {
            $codes = $e->result->codes();

            // Every problem at once, not just the first.
            $this->assertContains('unknown_recipient', $codes);
            $this->assertContains('dimension_not_positive', $codes);
            $this->assertNotEmpty($e->result->at('/fields/0/recipient_id'));
            $this->assertNotEmpty($e->result->at('/fields/1/rect/width'));
        }

        $this->assertDatabaseCount('template_versions', 0);
    }

    /**
     * Validity before availability, and the same answer the envelope boundary gives.
     *
     * `EnvelopeSourceSnapshotTest` pins the full four-cell matrix; this pins the cell the two
     * entry points once disagreed about, at the other entry point. A document that is malformed
     * *and* uses an option this deployment cannot honour is reported as malformed: it stays
     * malformed after anchor resolution ships, so answering "wait for resolution" would send a
     * sender to wait for something that will not help them.
     */
    public function test_a_malformed_document_using_a_gated_option_is_reported_as_malformed(): void
    {
        $document = $this->readyDocument();
        $schema = FieldSchemaFixture::asArray();
        $schema['fields'][0]['anchor'] = [
            'text' => 'Signature:',
            'occurrence' => 'sole',
            'placement' => AnchorPlacementMode::CrossCheck->value,
            'tolerance' => 2,
        ];
        $schema['fields'][0]['recipient_id'] = 'somebody_else';

        try {
            $this->templates->createDraftVersion($this->newTemplate(), $this->sender, $document, $schema);
            $this->fail('An invalid field schema was stored.');
        } catch (InvalidFieldSchemaException $e) {
            $codes = $e->result->codes();

            $this->assertContains('unknown_recipient', $codes);
            $this->assertNotContains('anchor_resolution_unavailable', $codes);
        }

        $this->assertDatabaseCount('template_versions', 0);
    }

    /** And a valid document using the gated option is refused as unavailable, with its own pointer. */
    public function test_a_valid_document_using_a_gated_option_is_refused_as_unavailable(): void
    {
        $document = $this->readyDocument();
        $schema = FieldSchemaFixture::asArray();
        $schema['fields'][0]['anchor'] = [
            'text' => 'Signature:',
            'occurrence' => 'sole',
            'placement' => AnchorPlacementMode::CrossCheck->value,
            'tolerance' => 2,
        ];

        try {
            $this->templates->createDraftVersion($this->newTemplate(), $this->sender, $document, $schema);
            $this->fail('A gated option was stored.');
        } catch (InvalidFieldSchemaException $e) {
            $this->assertSame(['anchor_resolution_unavailable'], $e->result->codes());
            $this->assertNotEmpty($e->result->at('/fields/0/anchor/placement'));
        }

        $this->assertDatabaseCount('template_versions', 0);
    }

    public function test_the_page_fit_check_uses_the_documents_own_preflight_geometry(): void
    {
        // The shared field set places fields on page 2; this document has one page.
        $onePage = $this->readyDocument('single-page-letter');

        try {
            $this->templates->createDraftVersion(
                $this->newTemplate(),
                $this->sender,
                $onePage,
                FieldSchemaFixture::asArray(),
            );
            $this->fail('A field set was stored against a document that has no such page.');
        } catch (InvalidFieldSchemaException $e) {
            $this->assertContains('page_out_of_range', $e->result->codes());
        }
    }

    public function test_a_document_that_failed_preflight_cannot_back_a_version(): void
    {
        $rejected = $this->intake('encrypted-aes128');

        $this->assertSame(DocumentStatus::PreflightFailed, $rejected->status);
        $this->assertNull($rejected->reviewRevision());

        try {
            $this->templates->createDraftVersion(
                $this->newTemplate(),
                $this->sender,
                $rejected,
                FieldSchemaFixture::asArray(),
            );
            $this->fail('A rejected document backed a template version.');
        } catch (TemplateStateException $e) {
            $this->assertSame('document_not_ready', $e->reason);
        }

        $this->assertDatabaseCount('template_versions', 0);
    }

    public function test_a_document_from_another_workspace_cannot_back_a_version(): void
    {
        $other = Workspace::factory()->create();
        $otherSender = DocumentWorkspace::memberOf($other, WorkspaceRole::Sender);
        $foreign = $this->intake('multi-page-mixed-size', $other, $otherSender);

        try {
            $this->templates->createDraftVersion(
                $this->newTemplate(),
                $this->sender,
                $foreign,
                FieldSchemaFixture::asArray(),
            );
            $this->fail('A template snapshotted a document from another workspace.');
        } catch (TemplateStateException $e) {
            $this->assertSame('document_in_another_workspace', $e->reason);
        }
    }

    public function test_a_retired_template_refuses_new_versions_and_publishes(): void
    {
        $document = $this->readyDocument();
        $template = $this->newTemplate();
        $draft = $this->templates->createDraftVersion($template, $this->sender, $document, FieldSchemaFixture::asArray());

        $this->templates->setRetired($template, $this->sender, true);

        try {
            $this->templates->createDraftVersion($template, $this->sender, $document, FieldSchemaFixture::asArray());
            $this->fail('A retired template accepted a new version.');
        } catch (TemplateStateException $e) {
            $this->assertSame('template_retired', $e->reason);
        }

        try {
            $this->templates->publish($draft->fresh(['template']) ?? $draft, $this->sender);
            $this->fail('A retired template published a version.');
        } catch (TemplateStateException $e) {
            $this->assertSame('template_retired', $e->reason);
        }

        // Restoring makes both possible again, and both transitions are audited.
        $this->templates->setRetired($template, $this->sender, false);
        $this->templates->publish($draft->fresh(['template']) ?? $draft, $this->sender);

        $this->assertTrue($draft->fresh()?->isPublished());
        $this->assertDatabaseHas('esign_audit_events', ['action' => 'preparation.template_retired']);
        $this->assertDatabaseHas('esign_audit_events', ['action' => 'preparation.template_restored']);
    }

    // ----------------------------------------------------------------- aliases

    public function test_an_alias_is_unique_per_workspace_and_not_across_workspaces(): void
    {
        $mine = $this->newTemplate('Mutual NDA');
        $alsoMine = $this->newTemplate('Order form');

        $alias = $this->templates->addAlias($mine, $this->sender, 'tpl_provider_9f2', TemplateAliasSource::ImportedProvider);

        $this->assertSame('tpl_provider_9f2', $alias->alias);
        $this->assertSame(TemplateAliasSource::ImportedProvider, $alias->source);
        $this->assertSame($this->workspace->getKey(), $alias->workspace_id);

        // The native id is never derived from the alias.
        $this->assertNotSame($alias->alias, $mine->public_id);

        try {
            $this->templates->addAlias($alsoMine, $this->sender, 'tpl_provider_9f2', TemplateAliasSource::ImportedProvider);
            $this->fail('An alias was mapped to two templates in one workspace.');
        } catch (TemplateStateException $e) {
            $this->assertSame('alias_already_taken', $e->reason);
        }

        // Another tenant migrating from the same provider presents the same id, and must
        // not be blocked by ours.
        $other = Workspace::factory()->create();
        $otherSender = DocumentWorkspace::memberOf($other, WorkspaceRole::Sender);
        $theirs = $this->templates->createTemplate($other, $otherSender, 'Their NDA');
        $theirAlias = $this->templates->addAlias($theirs, $otherSender, 'tpl_provider_9f2', TemplateAliasSource::ImportedProvider);

        $this->assertSame('tpl_provider_9f2', $theirAlias->alias);

        // And resolution stays inside a workspace.
        $this->assertSame($mine->getKey(), $this->templates->resolveAlias($this->workspace, 'tpl_provider_9f2')?->getKey());
        $this->assertSame($theirs->getKey(), $this->templates->resolveAlias($other, 'tpl_provider_9f2')?->getKey());
        $this->assertNull($this->templates->resolveAlias($this->workspace, 'tpl_provider_unknown'));
    }

    public function test_an_alias_can_be_removed_and_then_reused(): void
    {
        $template = $this->newTemplate();
        $this->templates->addAlias($template, $this->sender, 'legacy-nda', TemplateAliasSource::Manual);

        $this->templates->removeAlias($template, $this->sender, 'legacy-nda');

        $this->assertDatabaseCount('template_aliases', 0);
        $this->assertNull($this->templates->resolveAlias($this->workspace, 'legacy-nda'));
        $this->assertDatabaseHas('esign_audit_events', ['action' => 'preparation.template_alias_removed']);

        // Removing frees the name inside the workspace.
        $other = $this->newTemplate('Replacement NDA');
        $this->assertSame(
            'legacy-nda',
            $this->templates->addAlias($other, $this->sender, 'legacy-nda', TemplateAliasSource::Manual)->alias,
        );
    }

    public function test_removing_an_alias_this_template_does_not_have_is_refused(): void
    {
        $template = $this->newTemplate();

        try {
            $this->templates->removeAlias($template, $this->sender, 'never-registered');
            $this->fail('Removing an unknown alias succeeded.');
        } catch (TemplateStateException $e) {
            $this->assertSame('alias_not_found', $e->reason);
        }
    }

    // ------------------------------------------------------------------- audit

    public function test_every_mutation_writes_one_audit_event(): void
    {
        $document = $this->readyDocument();
        $template = $this->newTemplate();

        $this->templates->updateTemplate($template, $this->sender, ['name' => 'Renamed NDA']);
        $draft = $this->templates->createDraftVersion($template, $this->sender, $document, FieldSchemaFixture::asArray());
        $this->templates->updateDraftVersion($draft, $this->sender, $this->schemaWithout('counterparty_notes'));
        $this->templates->publish($draft, $this->sender);
        $this->templates->addAlias($template, $this->sender, 'tpl_provider_1', TemplateAliasSource::ImportedProvider);
        $this->templates->removeAlias($template, $this->sender, 'tpl_provider_1');
        $this->templates->setRetired($template, $this->sender, true);

        foreach ([
            'preparation.template_created',
            'preparation.template_updated',
            'preparation.template_version_drafted',
            'preparation.template_version_updated',
            'preparation.template_version_published',
            'preparation.template_alias_added',
            'preparation.template_alias_removed',
            'preparation.template_retired',
        ] as $action) {
            $this->assertDatabaseHas('esign_audit_events', [
                'action' => $action,
                'actor_type' => 'user',
                'actor_id' => (string) $this->sender->getKey(),
            ]);
        }
    }

    public function test_a_no_op_update_writes_nothing(): void
    {
        $template = $this->newTemplate();

        $this->templates->updateTemplate($template, $this->sender, []);
        $this->templates->setRetired($template, $this->sender, false);

        $this->assertSame(
            1,
            (int) AuditEvent::query()
                ->where('subject_id', (string) $template->getKey())
                ->count(),
            'Only the creation event should exist.',
        );
    }

    // ----------------------------------------------------------------- helpers

    private function newTemplate(string $name = 'Mutual NDA'): Template
    {
        return $this->templates->createTemplate($this->workspace, $this->sender, $name);
    }

    private function readyDocument(string $fixture = 'multi-page-mixed-size'): Document
    {
        return $this->intake($fixture);
    }

    private function intake(string $fixture, ?Workspace $workspace = null, ?User $uploader = null): Document
    {
        return app(DocumentIntake::class)->intake(
            $workspace ?? $this->workspace,
            $uploader ?? $this->sender,
            DocumentWorkspace::upload($fixture),
        );
    }

    private function publishedVersion(): TemplateVersion
    {
        $version = $this->templates->createDraftVersion(
            $this->newTemplate(),
            $this->sender,
            $this->readyDocument(),
            FieldSchemaFixture::asArray(),
        );

        return $this->templates->publish($version, $this->sender);
    }

    /**
     * The shared field set with one field dropped: a small, valid edit.
     *
     * @return array<string, mixed>
     */
    private function schemaWithout(string $fieldId): array
    {
        $schema = FieldSchemaFixture::asArray();

        /** @var list<array<string, mixed>> $fields */
        $fields = $schema['fields'];

        $schema['fields'] = array_values(array_filter(
            $fields,
            static fn (array $field): bool => $field['id'] !== $fieldId,
        ));

        return $schema;
    }
}
