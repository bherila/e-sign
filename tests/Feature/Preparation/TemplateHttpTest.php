<?php

declare(strict_types=1);

namespace Tests\Feature\Preparation;

use App\Domain\Identity\Enums\WorkspaceRole;
use App\Domain\Identity\Models\Workspace;
use App\Domain\Preparation\Documents\DocumentIntake;
use App\Domain\Preparation\Documents\Models\Document;
use App\Domain\Preparation\Schema\FieldSchemaDocument;
use App\Domain\Preparation\Templates\Models\Template;
use App\Domain\Preparation\Templates\Models\TemplateVersion;
use App\Domain\Preparation\Templates\TemplateService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\DocumentWorkspace;
use Tests\Support\FieldSchemaFixture;
use Tests\TestCase;

/**
 * The template HTTP surface: templates, versions, the canonical schema export, and aliases.
 *
 * Every request carries `Accept: application/json`, for the reason
 * tests/Feature/Preparation/DocumentHttpTest.php gives: Stage 2 has no template UI yet, and
 * an unauthenticated HTML request would be redirected to the `login` route the SSO work owns,
 * so asking for JSON keeps these tests about authorization rather than about which redirect
 * target exists this week.
 *
 * The isolation cases here are the HTTP half of what
 * tests/Feature/Identity/CrossWorkspaceIsolationTest.php requires, and they are registered
 * in its docblock list.
 */
class TemplateHttpTest extends TestCase
{
    use RefreshDatabase;

    private const JSON = ['Accept' => 'application/json'];

    private Workspace $workspace;

    private User $sender;

    private Document $document;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');

        $this->workspace = Workspace::factory()->create();
        $this->sender = DocumentWorkspace::memberOf($this->workspace, WorkspaceRole::Sender);
        // The PDF the shared field-schema fixture is written against: publishing resolves its
        // anchors against the revision the version snapshots, so the two have to match.
        $this->document = $this->intake('nda-two-signers');
    }

    protected function tearDown(): void
    {
        DocumentWorkspace::cleanUp();

        parent::tearDown();
    }

    // --------------------------------------------------------------- templates

    public function test_a_sender_can_create_and_read_a_template(): void
    {
        $response = $this->actingAs($this->sender)->post($this->templatesUrl(), [
            'name' => 'Mutual NDA',
            'description' => 'Two-signer mutual non-disclosure agreement.',
        ], self::JSON);

        $response->assertCreated()
            ->assertJsonPath('name', 'Mutual NDA')
            ->assertJsonPath('workspace_id', $this->workspace->public_id)
            ->assertJsonPath('current_version', null)
            ->assertJsonPath('retired_at', null)
            ->assertJsonCount(0, 'versions')
            ->assertJsonCount(0, 'aliases');

        $id = $response->json('id');
        $this->assertIsString($id);

        $this->actingAs($this->sender)
            ->get($this->templatesUrl().'/'.$id, self::JSON)
            ->assertOk()
            ->assertJsonPath('id', $id)
            ->assertJsonPath('description', 'Two-signer mutual non-disclosure agreement.');
    }

    public function test_a_template_needs_a_name(): void
    {
        $this->actingAs($this->sender)
            ->post($this->templatesUrl(), ['description' => 'No name'], self::JSON)
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->assertDatabaseCount('templates', 0);
    }

    public function test_the_index_lists_templates_and_filters_on_retirement(): void
    {
        $active = $this->createTemplate('Mutual NDA');
        $retired = $this->createTemplate('Withdrawn NDA');
        $this->service()->setRetired($retired, $this->sender, true);

        $this->actingAs($this->sender)->get($this->templatesUrl(), self::JSON)
            ->assertOk()
            ->assertJsonCount(2, 'templates');

        $this->actingAs($this->sender)->get($this->templatesUrl().'?retired=0', self::JSON)
            ->assertOk()
            ->assertJsonCount(1, 'templates')
            ->assertJsonPath('templates.0.id', $active->public_id);

        $this->actingAs($this->sender)->get($this->templatesUrl().'?retired=1', self::JSON)
            ->assertOk()
            ->assertJsonCount(1, 'templates')
            ->assertJsonPath('templates.0.id', $retired->public_id);
    }

    public function test_patch_renames_re_describes_and_retires(): void
    {
        $template = $this->createTemplate('Mutual NDA', 'First draft of the description.');

        $this->actingAs($this->sender)
            ->patch($this->templateUrl($template), ['name' => 'Mutual NDA (US)'], self::JSON)
            ->assertOk()
            ->assertJsonPath('name', 'Mutual NDA (US)')
            ->assertJsonPath('description', 'First draft of the description.');

        // Explicit null clears; omitting leaves alone.
        $this->actingAs($this->sender)
            ->patch($this->templateUrl($template), ['description' => null], self::JSON)
            ->assertOk()
            ->assertJsonPath('description', null)
            ->assertJsonPath('name', 'Mutual NDA (US)');

        $this->actingAs($this->sender)
            ->patch($this->templateUrl($template), ['retired' => true], self::JSON)
            ->assertOk()
            ->assertJsonPath('retired_at', fn (?string $at): bool => is_string($at));

        $this->actingAs($this->sender)
            ->patch($this->templateUrl($template), ['retired' => false], self::JSON)
            ->assertOk()
            ->assertJsonPath('retired_at', null);
    }

    // ---------------------------------------------------------------- versions

    public function test_a_version_is_drafted_from_a_document_and_a_field_schema(): void
    {
        $template = $this->createTemplate();

        $response = $this->actingAs($this->sender)->post($this->versionsUrl($template), [
            'document_id' => $this->document->public_id,
            'field_schema' => FieldSchemaFixture::asArray(),
            'consent_policy_version' => 'consent-2026-09-01',
            'render_settings' => ['date_format' => 'us', 'include_certificate_page' => false],
        ], self::JSON);

        $expectedDigest = hash('sha256', FieldSchemaDocument::fromArray(FieldSchemaFixture::asArray())->canonicalJson());

        $response->assertCreated()
            ->assertJsonPath('version', 1)
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('published_at', null)
            ->assertJsonPath('template_id', $template->public_id)
            ->assertJsonPath('document.id', $this->document->public_id)
            ->assertJsonPath('document.revision_id', $this->document->reviewRevision()?->public_id)
            ->assertJsonPath('document.sha256', $this->document->reviewRevision()?->sha256)
            ->assertJsonPath('field_schema_sha256', $expectedDigest)
            ->assertJsonPath('consent_policy_version', 'consent-2026-09-01')
            ->assertJsonPath('render_settings.date_format', 'us')
            ->assertJsonPath('render_settings.include_certificate_page', false)
            ->assertJsonPath('recipients.0.id', 'buyer')
            ->assertJsonPath('recipients.0.stage', 1)
            ->assertJsonPath('recipients.1.stage', 2)
            ->assertJsonPath('field_schema.schema_version', '1.0');

        // No storage handle in a version payload, for the same reason there is none in a
        // document payload.
        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('"disk"', $body);
        $this->assertStringNotContainsString('"path"', $body);
        $this->assertStringNotContainsString((string) $this->document->reviewRevision()?->path, $body);
    }

    public function test_a_version_can_be_addressed_by_number_or_by_ulid(): void
    {
        $template = $this->createTemplate();
        $version = $this->draftVersion($template);

        foreach (['1', $version->public_id] as $handle) {
            $this->actingAs($this->sender)
                ->get($this->versionsUrl($template).'/'.$handle, self::JSON)
                ->assertOk()
                ->assertJsonPath('id', $version->public_id)
                ->assertJsonPath('version', 1);
        }

        // Anything that is neither shape never reaches a query.
        foreach (['0', 'abc', '01'] as $malformed) {
            $this->actingAs($this->sender)
                ->get($this->versionsUrl($template).'/'.$malformed, self::JSON)
                ->assertNotFound();
        }
    }

    public function test_a_draft_can_be_patched_and_a_published_version_answers_409(): void
    {
        $template = $this->createTemplate();
        $version = $this->draftVersion($template);

        $this->actingAs($this->sender)
            ->patch($this->versionsUrl($template).'/1', [
                'consent_policy_version' => 'consent-2027-01-01',
                'render_settings' => ['signature_appearance' => 'drawn'],
            ], self::JSON)
            ->assertOk()
            ->assertJsonPath('version', 1)
            ->assertJsonPath('consent_policy_version', 'consent-2027-01-01')
            ->assertJsonPath('render_settings.signature_appearance', 'drawn');

        $this->actingAs($this->sender)
            ->post($this->versionsUrl($template).'/1/publish', [], self::JSON)
            ->assertOk()
            ->assertJsonPath('status', 'published');

        $this->actingAs($this->sender)
            ->patch($this->versionsUrl($template).'/1', ['consent_policy_version' => 'rewritten'], self::JSON)
            ->assertStatus(409)
            ->assertJsonPath('code', 'version_published');

        $this->assertSame('consent-2027-01-01', $version->fresh()?->consent_policy_version);
    }

    public function test_publishing_makes_the_version_current_and_a_second_publish_is_409(): void
    {
        $template = $this->createTemplate();
        $this->draftVersion($template);

        $this->actingAs($this->sender)
            ->post($this->versionsUrl($template).'/1/publish', [], self::JSON)
            ->assertOk()
            ->assertJsonPath('status', 'published');

        $this->actingAs($this->sender)
            ->get($this->templateUrl($template), self::JSON)
            ->assertOk()
            ->assertJsonPath('current_version.version', 1);

        $this->actingAs($this->sender)
            ->post($this->versionsUrl($template).'/1/publish', [], self::JSON)
            ->assertStatus(409)
            ->assertJsonPath('code', 'version_already_published');
    }

    public function test_editing_after_publishing_produces_version_two_and_leaves_version_one_alone(): void
    {
        $template = $this->createTemplate();
        $first = $this->draftVersion($template);
        $this->actingAs($this->sender)->post($this->versionsUrl($template).'/1/publish', [], self::JSON)->assertOk();

        $firstDigest = $first->fresh()?->field_schema_sha256;

        $schema = FieldSchemaFixture::asArray();
        /** @var list<array<string, mixed>> $fields */
        $fields = $schema['fields'];
        $schema['fields'] = array_values(array_filter(
            $fields,
            static fn (array $field): bool => $field['id'] !== 'counterparty_notes',
        ));

        $this->actingAs($this->sender)->post($this->versionsUrl($template), [
            'document_id' => $this->document->public_id,
            'field_schema' => $schema,
        ], self::JSON)
            ->assertCreated()
            ->assertJsonPath('version', 2)
            ->assertJsonPath('status', 'draft');

        // Version 1 is byte-for-byte what it was, and is still the current version.
        $this->assertSame($firstDigest, $first->fresh()?->field_schema_sha256);
        $this->actingAs($this->sender)
            ->get($this->templateUrl($template), self::JSON)
            ->assertJsonPath('current_version.version', 1)
            ->assertJsonCount(2, 'versions');

        $this->actingAs($this->sender)->post($this->versionsUrl($template).'/2/publish', [], self::JSON)->assertOk();

        $this->actingAs($this->sender)
            ->get($this->templateUrl($template), self::JSON)
            ->assertJsonPath('current_version.version', 2);
    }

    public function test_an_invalid_field_schema_returns_422_with_the_validators_structured_errors(): void
    {
        $template = $this->createTemplate();
        $schema = FieldSchemaFixture::asArray();
        $schema['fields'][0]['recipient_id'] = 'nobody';
        $schema['fields'][1]['rect']['width'] = 0;

        $response = $this->actingAs($this->sender)->post($this->versionsUrl($template), [
            'document_id' => $this->document->public_id,
            'field_schema' => $schema,
        ], self::JSON);

        $response->assertStatus(422)->assertJsonValidationErrors('field_schema');

        /** @var list<array{path: string, code: string, message: string}> $errors */
        $errors = $response->json('field_schema_errors');

        $this->assertContains('unknown_recipient', array_column($errors, 'code'));
        $this->assertContains('dimension_not_positive', array_column($errors, 'code'));
        // JSON Pointers, so the editor can annotate the offending fields in one pass.
        $this->assertContains('/fields/0/recipient_id', array_column($errors, 'path'));
        $this->assertContains('/fields/1/rect/width', array_column($errors, 'path'));

        $this->assertDatabaseCount('template_versions', 0);
    }

    public function test_a_missing_or_non_object_field_schema_is_refused_by_the_form_request(): void
    {
        $template = $this->createTemplate();

        $this->actingAs($this->sender)
            ->post($this->versionsUrl($template), ['document_id' => $this->document->public_id], self::JSON)
            ->assertStatus(422)
            ->assertJsonValidationErrors('field_schema');

        $this->actingAs($this->sender)
            ->post($this->versionsUrl($template), [
                'document_id' => $this->document->public_id,
                'field_schema' => 'not an object',
            ], self::JSON)
            ->assertStatus(422)
            ->assertJsonValidationErrors('field_schema');
    }

    public function test_an_unrecognised_render_setting_is_refused_rather_than_ignored(): void
    {
        $template = $this->createTemplate();

        $this->actingAs($this->sender)
            ->post($this->versionsUrl($template), [
                'document_id' => $this->document->public_id,
                'field_schema' => FieldSchemaFixture::asArray(),
                'render_settings' => ['watermark' => 'DRAFT'],
            ], self::JSON)
            ->assertStatus(422)
            ->assertJsonValidationErrors('render_settings');

        $this->actingAs($this->sender)
            ->post($this->versionsUrl($template), [
                'document_id' => $this->document->public_id,
                'field_schema' => FieldSchemaFixture::asArray(),
                'render_settings' => ['date_format' => 'yyyy/mm/dd'],
            ], self::JSON)
            ->assertStatus(422)
            ->assertJsonValidationErrors('render_settings.date_format');
    }

    public function test_a_document_that_is_not_ready_is_refused_with_an_actionable_422(): void
    {
        $template = $this->createTemplate();
        $rejected = $this->intake('encrypted-aes128');

        $response = $this->actingAs($this->sender)->post($this->versionsUrl($template), [
            'document_id' => $rejected->public_id,
            'field_schema' => FieldSchemaFixture::asArray(),
        ], self::JSON);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('document_id')
            ->assertJsonPath('code', 'document_not_ready');

        $this->assertStringContainsString('no review revision to snapshot', (string) $response->getContent());
        $this->assertDatabaseCount('template_versions', 0);
    }

    public function test_a_retired_template_refuses_a_new_version_with_409(): void
    {
        $template = $this->createTemplate();
        $this->service()->setRetired($template, $this->sender, true);

        $this->actingAs($this->sender)->post($this->versionsUrl($template), [
            'document_id' => $this->document->public_id,
            'field_schema' => FieldSchemaFixture::asArray(),
        ], self::JSON)
            ->assertStatus(409)
            ->assertJsonPath('code', 'template_retired');
    }

    // ------------------------------------------------------------ schema export

    public function test_the_schema_export_is_the_canonical_bytes_with_their_digest(): void
    {
        $template = $this->createTemplate();
        $version = $this->draftVersion($template);

        $response = $this->actingAs($this->sender)
            ->get($this->versionsUrl($template).'/1/schema.json', self::JSON);

        $expected = FieldSchemaDocument::fromArray(FieldSchemaFixture::asArray())->canonicalJson();

        $response->assertOk()
            ->assertHeader('X-Field-Schema-Sha256', $version->field_schema_sha256)
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
        $this->assertSame($expected, $response->getContent());
        $this->assertSame($version->field_schema_sha256, hash('sha256', (string) $response->getContent()));

        // Byte-identical on a second request: the export is deterministic, not a re-encode.
        $again = $this->actingAs($this->sender)->get($this->versionsUrl($template).'/1/schema.json', self::JSON);
        $this->assertSame($response->getContent(), $again->getContent());
    }

    // ----------------------------------------------------------------- aliases

    public function test_an_alias_can_be_added_and_removed(): void
    {
        $template = $this->createTemplate();

        $this->actingAs($this->sender)->post($this->templateUrl($template).'/aliases', [
            'alias' => 'tpl_provider_9f2',
            'source' => 'imported-provider',
        ], self::JSON)
            ->assertCreated()
            ->assertJsonPath('alias', 'tpl_provider_9f2')
            ->assertJsonPath('source', 'imported-provider');

        $this->actingAs($this->sender)
            ->get($this->templateUrl($template), self::JSON)
            ->assertJsonPath('aliases.0.alias', 'tpl_provider_9f2');

        $this->actingAs($this->sender)
            ->delete($this->templateUrl($template).'/aliases', ['alias' => 'tpl_provider_9f2'], self::JSON)
            ->assertOk()
            ->assertJsonPath('removed', true);

        $this->assertDatabaseCount('template_aliases', 0);
    }

    public function test_a_duplicate_alias_in_the_same_workspace_is_422_and_another_workspace_is_unaffected(): void
    {
        $mine = $this->createTemplate('Mutual NDA');
        $alsoMine = $this->createTemplate('Order form');

        $this->actingAs($this->sender)->post($this->templateUrl($mine).'/aliases', [
            'alias' => 'tpl_provider_9f2',
            'source' => 'imported-provider',
        ], self::JSON)->assertCreated();

        $this->actingAs($this->sender)->post($this->templateUrl($alsoMine).'/aliases', [
            'alias' => 'tpl_provider_9f2',
            'source' => 'imported-provider',
        ], self::JSON)
            ->assertStatus(422)
            ->assertJsonValidationErrors('alias')
            ->assertJsonPath('code', 'alias_already_taken');

        // The same provider id in another tenant is legitimate and must not be blocked.
        $other = Workspace::factory()->create();
        $otherSender = DocumentWorkspace::memberOf($other, WorkspaceRole::Sender);
        $theirs = $this->service()->createTemplate($other, $otherSender, 'Their NDA');

        $this->actingAs($otherSender)->post(
            '/workspaces/'.$other->public_id.'/templates/'.$theirs->public_id.'/aliases',
            ['alias' => 'tpl_provider_9f2', 'source' => 'imported-provider'],
            self::JSON,
        )->assertCreated();
    }

    public function test_an_unknown_source_or_a_hostile_alias_is_refused(): void
    {
        $template = $this->createTemplate();

        $this->actingAs($this->sender)->post($this->templateUrl($template).'/aliases', [
            'alias' => 'tpl_provider_9f2',
            'source' => 'made-up',
        ], self::JSON)->assertStatus(422)->assertJsonValidationErrors('source');

        $this->actingAs($this->sender)->post($this->templateUrl($template).'/aliases', [
            'alias' => "tpl\r\nX-Injected: 1",
            'source' => 'imported-provider',
        ], self::JSON)->assertStatus(422)->assertJsonValidationErrors('alias');

        $this->assertDatabaseCount('template_aliases', 0);
    }

    public function test_removing_an_alias_that_does_not_exist_is_a_404(): void
    {
        $template = $this->createTemplate();

        $this->actingAs($this->sender)
            ->delete($this->templateUrl($template).'/aliases', ['alias' => 'never-registered'], self::JSON)
            ->assertNotFound()
            ->assertJsonPath('code', 'alias_not_found');
    }

    // --------------------------------------------------------- authorization

    public function test_an_auditor_may_read_templates_and_versions_but_may_not_write(): void
    {
        $template = $this->createTemplate();
        $this->draftVersion($template);
        $auditor = DocumentWorkspace::memberOf($this->workspace, WorkspaceRole::Auditor);

        $this->actingAs($auditor)->get($this->templatesUrl(), self::JSON)->assertOk();
        $this->actingAs($auditor)->get($this->templateUrl($template), self::JSON)->assertOk();
        $this->actingAs($auditor)->get($this->versionsUrl($template).'/1', self::JSON)->assertOk();
        $this->actingAs($auditor)->get($this->versionsUrl($template).'/1/schema.json', self::JSON)->assertOk();

        $this->actingAs($auditor)->post($this->templatesUrl(), ['name' => 'Theirs'], self::JSON)->assertForbidden();
        $this->actingAs($auditor)->patch($this->templateUrl($template), ['name' => 'Renamed'], self::JSON)->assertForbidden();
        $this->actingAs($auditor)->post($this->versionsUrl($template), [
            'document_id' => $this->document->public_id,
            'field_schema' => FieldSchemaFixture::asArray(),
        ], self::JSON)->assertForbidden();
        $this->actingAs($auditor)->patch($this->versionsUrl($template).'/1', [
            'consent_policy_version' => 'x',
        ], self::JSON)->assertForbidden();
        $this->actingAs($auditor)->post($this->versionsUrl($template).'/1/publish', [], self::JSON)->assertForbidden();
        $this->actingAs($auditor)->post($this->templateUrl($template).'/aliases', [
            'alias' => 'tpl_1',
            'source' => 'manual',
        ], self::JSON)->assertForbidden();
        $this->actingAs($auditor)->delete($this->templateUrl($template).'/aliases', [
            'alias' => 'tpl_1',
        ], self::JSON)->assertForbidden();

        $this->assertDatabaseCount('templates', 1);
        $this->assertDatabaseCount('template_versions', 1);
        $this->assertDatabaseCount('template_aliases', 0);
    }

    public function test_an_unauthenticated_caller_is_denied_on_every_route(): void
    {
        $template = $this->createTemplate();
        $this->draftVersion($template);

        $this->get($this->templatesUrl(), self::JSON)->assertUnauthorized();
        $this->post($this->templatesUrl(), ['name' => 'Theirs'], self::JSON)->assertUnauthorized();
        $this->get($this->templateUrl($template), self::JSON)->assertUnauthorized();
        $this->patch($this->templateUrl($template), ['name' => 'Renamed'], self::JSON)->assertUnauthorized();
        $this->post($this->versionsUrl($template), [], self::JSON)->assertUnauthorized();
        $this->get($this->versionsUrl($template).'/1', self::JSON)->assertUnauthorized();
        $this->patch($this->versionsUrl($template).'/1', [], self::JSON)->assertUnauthorized();
        $this->post($this->versionsUrl($template).'/1/publish', [], self::JSON)->assertUnauthorized();
        $this->get($this->versionsUrl($template).'/1/schema.json', self::JSON)->assertUnauthorized();
        $this->post($this->templateUrl($template).'/aliases', [], self::JSON)->assertUnauthorized();
        $this->delete($this->templateUrl($template).'/aliases', [], self::JSON)->assertUnauthorized();
    }

    // -------------------------------------------------- cross-workspace isolation

    public function test_a_member_of_another_workspace_cannot_reach_this_one_by_public_id(): void
    {
        $template = $this->createTemplate();
        $other = Workspace::factory()->create();
        $outsider = DocumentWorkspace::memberOf($other, WorkspaceRole::Owner);

        // The workspace is a 404 rather than a 403: an outsider must not be able to tell a
        // workspace they cannot see from one that does not exist.
        $this->actingAs($outsider)->get($this->templatesUrl(), self::JSON)->assertNotFound();
        $this->actingAs($outsider)->get($this->templateUrl($template), self::JSON)->assertNotFound();
        $this->actingAs($outsider)
            ->post($this->templatesUrl(), ['name' => 'Theirs'], self::JSON)
            ->assertNotFound();
    }

    public function test_a_template_from_another_workspace_is_a_404_inside_a_workspace_the_caller_can_see(): void
    {
        $other = Workspace::factory()->create();
        $otherSender = DocumentWorkspace::memberOf($other, WorkspaceRole::Sender);
        $theirs = $this->service()->createTemplate($other, $otherSender, 'Their NDA');

        $url = $this->templatesUrl().'/'.$theirs->public_id;

        $this->actingAs($this->sender)->get($url, self::JSON)->assertNotFound();
        $this->actingAs($this->sender)->patch($url, ['name' => 'Mine now'], self::JSON)->assertNotFound();
        $this->actingAs($this->sender)->post($url.'/aliases', [
            'alias' => 'tpl_1',
            'source' => 'manual',
        ], self::JSON)->assertNotFound();
    }

    public function test_a_version_from_another_template_is_a_404(): void
    {
        $mine = $this->createTemplate('Mine');
        $theirTemplate = $this->createTemplate('Also mine, different template');
        $theirVersion = $this->draftVersion($theirTemplate);

        $this->actingAs($this->sender)
            ->get($this->versionsUrl($mine).'/'.$theirVersion->public_id, self::JSON)
            ->assertNotFound();

        // And by number: this template has no version 1.
        $this->actingAs($this->sender)
            ->get($this->versionsUrl($mine).'/1', self::JSON)
            ->assertNotFound();
    }

    public function test_a_document_from_another_workspace_passed_to_versions_is_a_404(): void
    {
        $template = $this->createTemplate();

        $other = Workspace::factory()->create();
        $otherSender = DocumentWorkspace::memberOf($other, WorkspaceRole::Sender);
        $theirDocument = app(DocumentIntake::class)->intake(
            $other,
            $otherSender,
            DocumentWorkspace::upload('multi-page-mixed-size'),
        );

        // A valid document ULID, presented under a workspace and template the caller owns.
        $this->actingAs($this->sender)->post($this->versionsUrl($template), [
            'document_id' => $theirDocument->public_id,
            'field_schema' => FieldSchemaFixture::asArray(),
        ], self::JSON)->assertNotFound();

        $this->assertDatabaseCount('template_versions', 0);
    }

    public function test_an_unknown_or_malformed_identifier_is_a_404(): void
    {
        $template = $this->createTemplate();

        $this->actingAs($this->sender)
            ->get($this->templatesUrl().'/'.Str::ulid(), self::JSON)
            ->assertNotFound();

        // Not a ULID at all: refused by the route constraint before a query runs.
        $this->actingAs($this->sender)
            ->get($this->templatesUrl().'/1', self::JSON)
            ->assertNotFound();

        // The autoincrement id is not routable, on either parameter.
        $this->actingAs($this->sender)
            ->get('/workspaces/'.$this->workspace->getKey().'/templates/'.$template->public_id, self::JSON)
            ->assertNotFound();
        $this->actingAs($this->sender)
            ->get($this->templatesUrl().'/'.$template->getKey(), self::JSON)
            ->assertNotFound();
    }

    // ----------------------------------------------------------------- helpers

    private function service(): TemplateService
    {
        return app(TemplateService::class);
    }

    private function templatesUrl(): string
    {
        return '/workspaces/'.$this->workspace->public_id.'/templates';
    }

    private function templateUrl(Template $template): string
    {
        return $this->templatesUrl().'/'.$template->public_id;
    }

    private function versionsUrl(Template $template): string
    {
        return $this->templateUrl($template).'/versions';
    }

    private function createTemplate(string $name = 'Mutual NDA', ?string $description = null): Template
    {
        return $this->service()->createTemplate($this->workspace, $this->sender, $name, $description);
    }

    private function draftVersion(Template $template): TemplateVersion
    {
        return $this->service()->createDraftVersion(
            $template,
            $this->sender,
            $this->document,
            FieldSchemaFixture::asArray(),
        );
    }

    private function intake(string $fixture): Document
    {
        return app(DocumentIntake::class)->intake(
            $this->workspace,
            $this->sender,
            DocumentWorkspace::upload($fixture),
        );
    }
}
