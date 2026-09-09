<?php

declare(strict_types=1);

namespace Tests\Feature\Preparation;

use App\Domain\Identity\Enums\WorkspaceRole;
use App\Domain\Identity\Models\Workspace;
use App\Domain\Preparation\Documents\DocumentIntake;
use App\Domain\Preparation\Documents\Models\Document;
use App\Domain\Preparation\Templates\Models\Template;
use App\Domain\Preparation\Templates\Models\TemplateVersion;
use App\Domain\Preparation\Templates\TemplateService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\DocumentWorkspace;
use Tests\Support\FieldSchemaFixture;
use Tests\TestCase;

/**
 * The visual field editor page (issue #22).
 *
 * The page is a Blade shell around one React island, so what a feature test can assert is
 * exactly what matters here: who may open it, what the server hands the island, and that the
 * read-only decision is made on the server rather than trusted to the browser.
 *
 * Its authorization is `view` rather than `createTemplates`, deliberately: an auditor reads
 * every template and changes none of them (docs/preparation/templates.md), and the only visual
 * view of a field set must not be the one thing that role cannot see. What an auditor does not
 * get is a Save button, and that is a server-side flag, not a client-side choice.
 *
 * The cross-workspace cases here are the HTTP half of what
 * tests/Feature/Identity/CrossWorkspaceIsolationTest.php requires, and they are registered in
 * its docblock list.
 */
class FieldEditorPageTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $sender;

    private Document $document;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');

        $this->workspace = Workspace::factory()->create();
        $this->sender = DocumentWorkspace::memberOf($this->workspace, WorkspaceRole::Sender);
        $this->document = $this->intake('nda-two-signers');
    }

    protected function tearDown(): void
    {
        DocumentWorkspace::cleanUp();

        parent::tearDown();
    }

    // ------------------------------------------------------------------ what the page carries

    public function test_a_sender_opens_the_editor_for_a_draft_version(): void
    {
        $template = $this->createTemplate();
        $version = $this->draftVersion($template);

        $response = $this->actingAs($this->sender)->get($this->editorUrl($template, $version));

        $response->assertOk()->assertViewIs('editor');

        $payload = $this->payload($response->viewData('editor'));

        $this->assertSame($this->workspace->public_id, $payload['workspace']['id']);
        $this->assertSame($template->public_id, $payload['template']['id']);
        $this->assertSame($version->public_id, $payload['version']['id']);
        $this->assertSame(1, $payload['version']['number']);
        $this->assertSame('draft', $payload['version']['status']);
        $this->assertFalse($payload['read_only']);
        $this->assertNull($payload['read_only_reason']);
    }

    public function test_the_payload_carries_every_url_the_island_needs_and_no_storage_key(): void
    {
        $template = $this->createTemplate();
        $version = $this->draftVersion($template);
        $revision = $this->document->reviewRevision();

        $payload = $this->payload(
            $this->actingAs($this->sender)
                ->get($this->editorUrl($template, $version))
                ->viewData('editor')
        );

        $base = '/workspaces/'.$this->workspace->public_id;

        $this->assertSame(
            $base.'/documents/'.$this->document->public_id.'/revisions/'.$revision?->public_id.'/view',
            parse_url($payload['urls']['document_view'], PHP_URL_PATH),
        );
        $this->assertSame(
            $base.'/templates/'.$template->public_id.'/versions/'.$version->public_id,
            parse_url($payload['urls']['save'], PHP_URL_PATH),
        );
        $this->assertSame(
            $base.'/templates/'.$template->public_id.'/versions/'.$version->public_id.'/schema.json',
            parse_url($payload['urls']['schema'], PHP_URL_PATH),
        );

        // Bytes are reached through the streaming download routes; a client that knew a
        // storage key would be one presigning bug away from bypassing the workspace policy.
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('documents/', (string) ($payload['document']['id'] ?? ''));
        $this->assertStringNotContainsString('object_key', $encoded);
        $this->assertStringNotContainsString('disk', $encoded);
    }

    public function test_the_payload_carries_the_canonical_field_set_and_its_digest(): void
    {
        $template = $this->createTemplate();
        $version = $this->draftVersion($template);

        $payload = $this->payload(
            $this->actingAs($this->sender)
                ->get($this->editorUrl($template, $version))
                ->viewData('editor')
        );

        $this->assertSame($version->field_schema_sha256, $payload['field_schema_sha256']);
        $this->assertSame(
            $version->fieldSchemaDocument()->toArray(),
            $payload['field_schema'],
        );
        $this->assertSame(
            hash('sha256', $version->canonicalFieldSchemaJson()),
            $payload['field_schema_sha256'],
        );
    }

    public function test_the_payload_carries_the_displayed_geometry_of_every_page(): void
    {
        // Its own document: the class fixture is the two-page PDF the shared field set's
        // anchors are written against, and this case is about page geometry.
        $mixed = $this->intake('multi-page-mixed-size');
        $template = $this->createTemplate('Mixed sizes');
        $version = $this->service()->createDraftVersion(
            $template,
            $this->sender,
            $mixed,
            $this->emptyFieldSchema(),
        );

        $payload = $this->payload(
            $this->actingAs($this->sender)
                ->get($this->editorUrl($template, $version))
                ->viewData('editor')
        );

        // The fixture is three pages of different sizes, which is the case HANDOFF §7 calls
        // out: an editor that assumed one page size would place fields off two of them.
        $this->assertCount(3, $payload['pages']);
        $this->assertSame(
            [[612.0, 792.0], [595.0, 842.0], [400.0, 600.0]],
            array_map(
                static fn (array $page): array => [$page['native_width'], $page['native_height']],
                $payload['pages'],
            ),
        );

        foreach ($payload['pages'] as $index => $page) {
            $this->assertSame($index + 1, $page['page'], 'Page numbers are 1-based and in order.');
            $this->assertCount(4, $page['crop_box']);
            $this->assertSame(0, $page['rotation']);
            $this->assertSame(1.0, $page['user_unit']);
        }
    }

    public function test_a_rotated_page_reports_its_displayed_size_and_its_rotation(): void
    {
        $rotated = $this->intake('rotated-pages');
        $template = $this->createTemplate('Rotated');
        $version = app(TemplateService::class)->createDraftVersion(
            $template,
            $this->sender,
            $rotated,
            $this->emptyFieldSchema(),
        );

        $payload = $this->payload(
            $this->actingAs($this->sender)
                ->get($this->editorUrl($template, $version))
                ->viewData('editor')
        );

        // Letter pages rotated 90, 180 and 270. Displayed width and height transpose on a
        // quarter turn (docs/preparation/coordinate-space.md), and the editor is given the
        // displayed numbers because that is the space field rectangles are stored in.
        $this->assertSame(
            [[90, 792.0, 612.0], [180, 612.0, 792.0], [270, 792.0, 612.0]],
            array_map(
                static fn (array $page): array => [
                    $page['rotation'],
                    $page['native_width'],
                    $page['native_height'],
                ],
                $payload['pages'],
            ),
        );
    }

    public function test_an_offset_cropbox_is_handed_over_rather_than_normalised_away(): void
    {
        $offset = $this->intake('cropbox-offset');
        $template = $this->createTemplate('Offset');
        $version = app(TemplateService::class)->createDraftVersion(
            $template,
            $this->sender,
            $offset,
            $this->emptyFieldSchema(),
        );

        $payload = $this->payload(
            $this->actingAs($this->sender)
                ->get($this->editorUrl($template, $version))
                ->viewData('editor')
        );

        $this->assertSame([36.0, 48.0, 576.0, 744.0], $payload['pages'][0]['crop_box']);
        $this->assertSame(540.0, $payload['pages'][0]['native_width']);
        $this->assertSame(696.0, $payload['pages'][0]['native_height']);
    }

    public function test_the_prefill_variable_list_is_empty_unless_the_deployment_declares_one(): void
    {
        $template = $this->createTemplate();
        $version = $this->draftVersion($template);

        $payload = $this->payload(
            $this->actingAs($this->sender)
                ->get($this->editorUrl($template, $version))
                ->viewData('editor')
        );

        // A template has no sending context, so the resolvability check is not run here and
        // the editor is told to skip it rather than run it against nothing.
        $this->assertSame([], $payload['variables']);

        config()->set('esign.preparation.prefill_variables', ['recipient.name', ' envelope.agreement_date ', '']);

        $payload = $this->payload(
            $this->actingAs($this->sender)
                ->get($this->editorUrl($template, $version))
                ->viewData('editor')
        );

        $this->assertSame(['recipient.name', 'envelope.agreement_date'], $payload['variables']);
    }

    public function test_the_payload_is_rendered_into_the_page_as_escaped_json(): void
    {
        $template = $this->createTemplate();
        $version = $this->draftVersion($template);

        $html = $this->actingAs($this->sender)
            ->get($this->editorUrl($template, $version))
            ->getContent();

        $this->assertIsString($html);
        $this->assertMatchesRegularExpression('/id="field-editor"/', $html);

        $matched = preg_match('/data-editor="([^"]*)"/', $html, $matches) === 1;
        $this->assertTrue($matched, 'The island did not receive a data-editor attribute.');

        $decoded = json_decode(html_entity_decode($matches[1], ENT_QUOTES), true, 64, JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded);
        $this->assertSame($version->public_id, $decoded['version']['id']);
        $this->assertSame(csrf_token(), $decoded['csrf_token']);
    }

    // ---------------------------------------------------------------------------- read-only

    public function test_a_published_version_opens_read_only(): void
    {
        $template = $this->createTemplate();
        $version = $this->service()->publish($this->draftVersion($template), $this->sender);

        $payload = $this->payload(
            $this->actingAs($this->sender)
                ->get($this->editorUrl($template, $version))
                ->viewData('editor')
        );

        $this->assertSame('published', $payload['version']['status']);
        $this->assertTrue($payload['read_only']);
        $this->assertSame('version_published', $payload['read_only_reason']);
    }

    public function test_an_auditor_opens_the_editor_read_only(): void
    {
        $auditor = DocumentWorkspace::memberOf($this->workspace, WorkspaceRole::Auditor);
        $template = $this->createTemplate();
        $version = $this->draftVersion($template);

        $response = $this->actingAs($auditor)->get($this->editorUrl($template, $version));
        $payload = $this->payload($response->viewData('editor'));

        $response->assertOk();
        $this->assertTrue($payload['read_only']);
        $this->assertSame('insufficient_role', $payload['read_only_reason']);
        // Read-only for the role, but the field set itself is there to be read.
        $this->assertNotEmpty($payload['field_schema']['fields']);
    }

    public function test_publication_outranks_the_role_as_the_read_only_reason(): void
    {
        $auditor = DocumentWorkspace::memberOf($this->workspace, WorkspaceRole::Auditor);
        $template = $this->createTemplate();
        $version = $this->service()->publish($this->draftVersion($template), $this->sender);

        $payload = $this->payload(
            $this->actingAs($auditor)->get($this->editorUrl($template, $version))->viewData('editor')
        );

        // Both apply; the one reported is the one nobody can resolve by changing a role.
        $this->assertSame('version_published', $payload['read_only_reason']);
    }

    // ----------------------------------------------------------------------- authorization

    public function test_an_unauthenticated_caller_is_sent_to_sign_in(): void
    {
        $template = $this->createTemplate();
        $version = $this->draftVersion($template);

        $this->get($this->editorUrl($template, $version))->assertRedirect(route('login'));
    }

    public function test_a_member_of_another_workspace_gets_a_404_rather_than_a_403(): void
    {
        $template = $this->createTemplate();
        $version = $this->draftVersion($template);

        $other = Workspace::factory()->create();
        $outsider = DocumentWorkspace::memberOf($other, WorkspaceRole::Owner);

        // 404, not 403: an outsider must not be able to tell a workspace they cannot see from
        // one that does not exist.
        $this->actingAs($outsider)->get($this->editorUrl($template, $version))->assertNotFound();
    }

    public function test_a_template_from_another_workspace_is_a_404(): void
    {
        $other = Workspace::factory()->create();
        $otherSender = DocumentWorkspace::memberOf($other, WorkspaceRole::Sender);
        $theirs = $this->service()->createTemplate($other, $otherSender, 'Their NDA');

        $url = '/workspaces/'.$this->workspace->public_id
            .'/templates/'.$theirs->public_id.'/versions/1/editor';

        $this->actingAs($this->sender)->get($url)->assertNotFound();
    }

    public function test_a_version_from_another_template_is_a_404(): void
    {
        $mine = $this->createTemplate('Mine');
        $theirTemplate = $this->createTemplate('Also mine, different template');
        $theirVersion = $this->draftVersion($theirTemplate);

        $this->actingAs($this->sender)
            ->get($this->editorUrl($mine, $theirVersion))
            ->assertNotFound();
    }

    public function test_a_version_is_addressable_by_number_or_by_ulid(): void
    {
        $template = $this->createTemplate();
        $version = $this->draftVersion($template);

        $byNumber = $this->actingAs($this->sender)->get(
            '/workspaces/'.$this->workspace->public_id
            .'/templates/'.$template->public_id.'/versions/1/editor'
        );

        $byUlid = $this->actingAs($this->sender)->get($this->editorUrl($template, $version));

        $byNumber->assertOk();
        $byUlid->assertOk();
        $this->assertSame(
            $this->payload($byNumber->viewData('editor'))['version']['id'],
            $this->payload($byUlid->viewData('editor'))['version']['id'],
        );
    }

    public function test_a_malformed_identifier_is_a_404_at_the_router(): void
    {
        $template = $this->createTemplate();

        $this->actingAs($this->sender)->get(
            '/workspaces/not-a-ulid/templates/'.$template->public_id.'/versions/1/editor'
        )->assertNotFound();

        $this->actingAs($this->sender)->get(
            '/workspaces/'.$this->workspace->public_id
            .'/templates/'.$template->public_id.'/versions/0/editor'
        )->assertNotFound();
    }

    // ------------------------------------------------------------------------------ helpers

    /**
     * @return array<string, mixed>
     */
    private function payload(mixed $editor): array
    {
        $this->assertIsArray($editor);

        /** @var array<string, mixed> $editor */
        return $editor;
    }

    private function editorUrl(Template $template, TemplateVersion $version): string
    {
        return '/workspaces/'.$this->workspace->public_id
            .'/templates/'.$template->public_id
            .'/versions/'.$version->public_id.'/editor';
    }

    private function service(): TemplateService
    {
        return app(TemplateService::class);
    }

    private function createTemplate(string $name = 'Mutual NDA'): Template
    {
        return $this->service()->createTemplate($this->workspace, $this->sender, $name);
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

    /**
     * A valid document with no fields, for the geometry cases: an empty field set is a valid
     * draft, and it keeps those tests about page geometry rather than about placement.
     *
     * @return array<string, mixed>
     */
    private function emptyFieldSchema(): array
    {
        $schema = FieldSchemaFixture::asArray();
        $schema['fields'] = [];

        return $schema;
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
