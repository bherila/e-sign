<?php

declare(strict_types=1);

namespace Tests\Feature\Preparation;

use App\Domain\Identity\Audit\AuditEvent;
use App\Domain\Identity\Enums\WorkspaceRole;
use App\Domain\Identity\Models\Workspace;
use App\Domain\Preparation\Anchoring\RevisionAnchorResolver;
use App\Domain\Preparation\Contracts\PdfTextLocator;
use App\Domain\Preparation\Documents\DocumentIntake;
use App\Domain\Preparation\Documents\Models\Document;
use App\Domain\Preparation\Schema\AnchorPlacementMode;
use App\Domain\Preparation\Schema\ValidationCode;
use App\Domain\Preparation\Templates\Models\Template;
use App\Domain\Preparation\Templates\Models\TemplateVersion;
use App\Domain\Preparation\Templates\TemplateService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use Tests\Support\DocumentWorkspace;
use Tests\Support\FieldSchemaFixture;
use Tests\TestCase;

/**
 * Publish-time anchor resolution (issue #23).
 *
 * Publishing is where an anchor stops being a request and becomes a rectangle, and — because a
 * version snapshots one immutable review revision — it is the first moment the field set and the
 * bytes it will be placed on are both fixed. So it is also the first moment an unplaceable
 * anchor can be reported, while the sender is still authoring and a fix costs nothing.
 *
 * The failures come back the way every other field-schema failure does: a 422 carrying every
 * problem at once, each with a stable code and a JSON Pointer to the offending field. That is
 * the shape the editor annotates from, so an anchor that cannot be resolved lands on the field
 * rather than on the document.
 *
 * `nda-two-signers.pdf` is the document: page 2 carries "Counterparty signature:" once and
 * "Notes:" twice, which is what makes both `sole` and an occurrence index testable here.
 */
class TemplateAnchorPublishTest extends TestCase
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
        $this->document = app(DocumentIntake::class)->intake(
            $this->workspace,
            $this->sender,
            DocumentWorkspace::upload('nda-two-signers'),
        );
    }

    protected function tearDown(): void
    {
        DocumentWorkspace::cleanUp();

        parent::tearDown();
    }

    // ------------------------------------------------------------------------ resolution

    public function test_publishing_writes_the_resolved_rectangle_and_its_receipt(): void
    {
        $template = $this->template();
        $draft = $this->draft($template, FieldSchemaFixture::asArray());
        $beforeDigest = $draft->field_schema_sha256;

        $this->actingAs($this->sender)
            ->post($this->publishUrl($template), [], self::JSON)
            ->assertOk()
            ->assertJsonPath('status', 'published');

        $published = $draft->fresh();
        $this->assertNotNull($published);
        $schema = $published->fieldSchemaDocument();
        $field = $schema->field('counterparty_signature');

        $this->assertNotNull($field);
        // "Counterparty signature:" is at native (330, 622.4) and 12 pt tall; the anchor
        // measures from its bottom-left corner, 12.5 pt down.
        $this->assertSame(['x' => 330, 'y' => 646.9, 'width' => 170, 'height' => 36], $field->rect->toArray());
        $this->assertSame(
            $this->document->reviewRevision()?->sha256,
            $field->anchor?->resolved?->documentSha256,
        );

        $this->assertNotSame($beforeDigest, $published->field_schema_sha256);
        $this->assertSame(hash('sha256', $schema->canonicalJson()), $published->field_schema_sha256);
    }

    public function test_the_resolution_is_audited_with_the_fields_it_placed(): void
    {
        $template = $this->template();
        $this->draft($template, FieldSchemaFixture::asArray());

        app(TemplateService::class)->publish($this->versionOf($template), $this->sender);

        $event = AuditEvent::query()
            ->where('action', 'preparation.template_version_anchors_resolved')
            ->sole();

        $payload = $event->payload;
        $this->assertIsArray($payload);
        $this->assertSame(['counterparty_signature', 'counterparty_notes'], $payload['resolved_field_ids']);
        $this->assertSame(0, $payload['omitted_count']);
    }

    public function test_a_document_with_no_anchors_publishes_with_its_digest_untouched(): void
    {
        $template = $this->template();
        $draft = $this->draft($template, $this->schemaWithout('anchor'));
        $before = [$draft->canonicalFieldSchemaJson(), $draft->field_schema_sha256];

        app(TemplateService::class)->publish($this->versionOf($template), $this->sender);

        $published = $draft->fresh();

        // Compared through the canonical form, never as the raw column: a MySQL JSON column
        // does not preserve object key order, so the bytes that come back are not necessarily
        // the bytes that went in (docs/preparation/templates.md).
        $this->assertSame($before, [$published?->canonicalFieldSchemaJson(), $published?->field_schema_sha256]);
    }

    /**
     * The document is read before the transaction opens, not inside it.
     *
     * Resolving means a private-disk read and a full content-stream parse. Under
     * `lockForUpdate()` that would hold the template-version row for the length of a PDF parse,
     * which is where lock-wait timeouts start; `send()` deliberately does not do it, and neither
     * does this. The observable proof is that no transaction is open while the document is being
     * read.
     */
    public function test_the_document_is_read_before_the_row_is_locked(): void
    {
        $template = $this->template();
        $this->draft($template, FieldSchemaFixture::asArray());

        // RefreshDatabase already holds a transaction open around the test, so the baseline is
        // whatever depth we are at now; publish opening its own would take it one deeper.
        $baseline = DB::transactionLevel();
        $depthWhileReading = null;
        $locator = app(PdfTextLocator::class);

        app()->instance(PdfTextLocator::class, new class($locator, $depthWhileReading) implements PdfTextLocator
        {
            public function __construct(private readonly PdfTextLocator $inner, public mixed &$depth) {}

            public function extract(string $pdfBytes, ?int $page = null): array
            {
                $this->depth = DB::transactionLevel();

                return $this->inner->extract($pdfBytes, $page);
            }
        });

        app(TemplateService::class)->publish($this->versionOf($template), $this->sender);

        $this->assertSame(
            $baseline,
            $depthWhileReading,
            'The PDF was parsed inside the publish transaction, holding the row lock across it.',
        );
        $this->assertNotNull($this->versionOf($template)->published_at);
    }

    /**
     * And the result is still confirmed against the row that holds the lock.
     *
     * Resolving outside the transaction means the draft could in principle be edited in between,
     * so the outcome records the digest of the field set it ran against and a disagreement
     * resolves again under the lock rather than being stored.
     */
    public function test_a_resolution_that_ran_against_a_different_field_set_is_not_stored(): void
    {
        $template = $this->template();
        $draft = $this->draft($template, FieldSchemaFixture::asArray());

        $service = app(TemplateService::class);
        $stale = $draft->fieldSchemaDocument();

        // A pass computed against a field set that is not the one on the row.
        $prepared = app(RevisionAnchorResolver::class)->resolve(
            $draft->documentRevision,
            $stale->withFields([$stale->fields[0]]),
            omitAbsentFields: false,
        );

        $confirmed = (new ReflectionMethod($service, 'resolutionFor'))->invoke($service, $draft, $prepared);

        // Discarded and redone: the confirmed outcome describes the whole field set on the row.
        $this->assertCount(count($stale->fields), $confirmed->schema->fields);
        $this->assertSame(
            hash('sha256', $draft->fieldSchemaDocument()->canonicalJson()),
            $confirmed->sourceSchemaSha256,
        );
    }

    // ------------------------------------------------------------------------ visible failure

    public function test_an_absent_required_anchor_fails_the_publish_with_a_pointer_to_the_field(): void
    {
        $template = $this->template();
        $draft = $this->draft($template, $this->withAnchor(['text' => 'Witness signature:']));

        $response = $this->actingAs($this->sender)
            ->post($this->publishUrl($template), [], self::JSON)
            ->assertStatus(422);

        $response->assertJsonPath('field_schema_errors.0.code', ValidationCode::AnchorNotFound->value)
            ->assertJsonPath('field_schema_errors.0.path', '/fields/5/anchor');

        $this->assertStringContainsString('"counterparty_signature"', (string) $response->json('field_schema_errors.0.message'));
        $this->assertStringContainsString('Witness signature:', (string) $response->json('field_schema_errors.0.message'));

        // Nothing was saved: the version is still a draft and still editable.
        $this->assertNull($draft->fresh()?->published_at);
        $this->assertSame($draft->field_schema_sha256, $draft->fresh()?->field_schema_sha256);
    }

    public function test_an_ambiguous_anchor_fails_the_publish(): void
    {
        // "Notes:" occurs twice on page 2, and `sole` means exactly once.
        $template = $this->template();
        $this->draft($template, $this->withAnchor(['text' => 'Notes:', 'occurrence' => 'sole']));

        $this->actingAs($this->sender)
            ->post($this->publishUrl($template), [], self::JSON)
            ->assertStatus(422)
            ->assertJsonPath('field_schema_errors.0.code', ValidationCode::AnchorAmbiguous->value);
    }

    public function test_an_occurrence_beyond_the_matches_fails_the_publish(): void
    {
        $template = $this->template();
        $this->draft($template, $this->withAnchor(['text' => 'Notes:', 'occurrence' => 5]));

        $this->actingAs($this->sender)
            ->post($this->publishUrl($template), [], self::JSON)
            ->assertStatus(422)
            ->assertJsonPath('field_schema_errors.0.code', ValidationCode::AnchorOccurrenceOutOfRange->value);
    }

    public function test_a_cross_check_that_disagrees_with_the_declared_rectangle_fails_the_publish(): void
    {
        $template = $this->template();
        $this->draft($template, $this->withAnchor([
            'text' => 'Counterparty signature:',
            'occurrence' => 'sole',
            'placement' => AnchorPlacementMode::CrossCheck->value,
            'tolerance' => 1,
        ]));

        // The declared rectangle is at (330, 650); the anchor resolves to (330, 622.4).
        $this->actingAs($this->sender)
            ->post($this->publishUrl($template), [], self::JSON)
            ->assertStatus(422)
            ->assertJsonPath('field_schema_errors.0.code', ValidationCode::AnchorCrossCheckFailed->value);
    }

    public function test_a_cross_check_within_the_tolerance_publishes_and_keeps_the_declared_rectangle(): void
    {
        $template = $this->template();
        $draft = $this->draft($template, $this->withAnchor([
            'text' => 'Counterparty signature:',
            'occurrence' => 'sole',
            'placement' => AnchorPlacementMode::CrossCheck->value,
            'tolerance' => 40,
        ]));

        $this->actingAs($this->sender)->post($this->publishUrl($template), [], self::JSON)->assertOk();

        $field = $draft->fresh()?->fieldSchemaDocument()->field('counterparty_signature');

        $this->assertNotNull($field);
        $this->assertSame(['x' => 330, 'y' => 650, 'width' => 170, 'height' => 36], $field->rect->toArray());
        $this->assertNotNull($field->anchor?->resolved, 'The check is recorded even though it moved nothing.');
    }

    // ------------------------------------------------------------------------ optional absence

    public function test_an_absent_optional_anchor_is_reported_at_publish_without_removing_the_field(): void
    {
        $template = $this->template();
        $draft = $this->draft($template, $this->withOptionalAbsentNotes());

        $this->actingAs($this->sender)->post($this->publishUrl($template), [], self::JSON)->assertOk();

        // Which fields an envelope leaves out is a fact about that envelope, not about the
        // template, so publishing reports the absence and changes nothing.
        $this->assertNotNull($draft->fresh()?->fieldSchemaDocument()->field('counterparty_notes'));

        $payload = AuditEvent::query()
            ->where('action', 'preparation.template_version_anchors_resolved')
            ->sole()
            ->payload;

        $this->assertIsArray($payload);
        $this->assertFalse($payload['omissions_applied']);
        $this->assertSame('counterparty_notes', $payload['omitted_fields'][0]['field_id']);
        $this->assertSame('optional_anchor_absent', $payload['omitted_fields'][0]['reason']);
    }

    public function test_an_optional_anchor_on_a_required_field_is_refused_when_the_draft_is_stored(): void
    {
        $schema = $this->withAnchor(['text' => 'Anywhere:', 'required' => false]);

        // `counterparty_signature` is a required field, and an absent anchor omits the field it
        // is on. The combination is refused where it is written, not discovered at publish.
        $this->actingAs($this->sender)
            ->post($this->versionsUrl($this->template()), [
                'document_id' => $this->document->public_id,
                'field_schema' => $schema,
            ], self::JSON)
            ->assertStatus(422)
            ->assertJsonPath('field_schema_errors.0.code', ValidationCode::AnchorOptionalOnRequiredField->value)
            ->assertJsonPath('field_schema_errors.0.path', '/fields/5/anchor/required');
    }

    // ------------------------------------------------------------------------ authorization

    public function test_a_member_of_another_workspace_still_gets_a_404_rather_than_a_resolution_error(): void
    {
        $template = $this->template();
        $this->draft($template, $this->withAnchor(['text' => 'Witness signature:']));

        $outsider = DocumentWorkspace::memberOf(Workspace::factory()->create(), WorkspaceRole::Owner);

        // The refusal is about who is asking, and it comes before anything opens a PDF.
        $this->actingAs($outsider)->post($this->publishUrl($template), [], self::JSON)->assertNotFound();
        $this->assertNull(TemplateVersion::query()->sole()->published_at);
    }

    public function test_an_auditor_cannot_publish_even_a_perfectly_resolvable_version(): void
    {
        $auditor = DocumentWorkspace::memberOf($this->workspace, WorkspaceRole::Auditor);
        $template = $this->template();
        $this->draft($template, FieldSchemaFixture::asArray());

        $this->actingAs($auditor)->post($this->publishUrl($template), [], self::JSON)->assertForbidden();
        $this->assertNull(TemplateVersion::query()->sole()->published_at);
    }

    // ------------------------------------------------------------------------ helpers

    /**
     * The shared fixture with the counterparty signature's anchor replaced.
     *
     * @param  array<string, mixed>  $anchor
     * @return array<string, mixed>
     */
    private function withAnchor(array $anchor): array
    {
        $schema = FieldSchemaFixture::asArray();
        $schema['fields'][5]['anchor'] = array_replace([
            'occurrence' => 'sole',
            'placement' => AnchorPlacementMode::Replace->value,
        ], $anchor);

        return $schema;
    }

    /**
     * The shared fixture with the optional notes field pointed at text the document has not got.
     *
     * @return array<string, mixed>
     */
    private function withOptionalAbsentNotes(): array
    {
        $schema = FieldSchemaFixture::asArray();
        $schema['fields'][9]['anchor'] = [
            'text' => 'Witness signature:',
            'occurrence' => 'sole',
            'placement' => AnchorPlacementMode::Replace->value,
            'required' => false,
        ];

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    private function schemaWithout(string $property): array
    {
        $schema = FieldSchemaFixture::asArray();

        foreach ($schema['fields'] as $index => $field) {
            unset($schema['fields'][$index][$property]);
        }

        return $schema;
    }

    private function template(string $name = 'Mutual NDA'): Template
    {
        return app(TemplateService::class)->createTemplate($this->workspace, $this->sender, $name);
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function draft(Template $template, array $schema): TemplateVersion
    {
        return app(TemplateService::class)->createDraftVersion(
            $template,
            $this->sender,
            $this->document,
            $schema,
        );
    }

    private function versionOf(Template $template): TemplateVersion
    {
        return TemplateVersion::query()->where('template_id', $template->getKey())->sole();
    }

    private function versionsUrl(Template $template): string
    {
        return '/workspaces/'.$this->workspace->public_id.'/templates/'.$template->public_id.'/versions';
    }

    private function publishUrl(Template $template): string
    {
        return $this->versionsUrl($template).'/1/publish';
    }
}
