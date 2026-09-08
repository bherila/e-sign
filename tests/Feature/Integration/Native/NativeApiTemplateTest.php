<?php

declare(strict_types=1);

namespace Tests\Feature\Integration\Native;

use App\Domain\Integration\Native\Page;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\NativeApiScenario;
use Tests\Support\SigningFixtures;
use Tests\TestCase;

/**
 * The template catalogue an integration picks a version out of.
 *
 * The one property worth guarding hard is that **only published versions are ever reported**.
 * A draft can still change, and `POST /envelopes` refuses one, so offering a draft's id here
 * would hand an integrator a value that produces a 422 the first time they use it.
 */
class NativeApiTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_templates_are_listed_with_their_current_published_version(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();
        $version = $scenario->publishedTemplateVersion();

        $this->getJson('/api/v1/templates', NativeApiScenario::headers($issued))
            ->assertOk()
            ->assertJsonPath('data.0.id', $version->template->public_id)
            ->assertJsonPath('data.0.name', 'Synthetic mutual NDA template')
            ->assertJsonPath('data.0.retired_at', null)
            ->assertJsonPath('data.0.current_version.id', $version->public_id)
            ->assertJsonPath('data.0.current_version.version', 1)
            ->assertJsonPath('data.0.current_version.field_schema_sha256', $version->field_schema_sha256)
            ->assertJsonPath('data.0.current_version.document.sha256', $scenario->signing->revision->sha256)
            ->assertJsonPath('meta.next_cursor', null)
            // The list is for choosing a template; the version history is on the detail.
            ->assertJsonMissingPath('data.0.versions');
    }

    public function test_a_template_detail_carries_its_published_versions(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();
        $version = $scenario->publishedTemplateVersion();

        $this->getJson('/api/v1/templates/'.$version->template->public_id, NativeApiScenario::headers($issued))
            ->assertOk()
            ->assertJsonPath('id', $version->template->public_id)
            ->assertJsonPath('versions.0.id', $version->public_id)
            ->assertJsonPath('versions.0.consent_policy_version', SigningFixtures::CONSENT_VERSION)
            ->assertJsonPath('versions.0.recipients.0.id', 'buyer')
            ->assertJsonPath('versions.0.recipients.1.id', 'seller');
    }

    public function test_a_draft_version_is_never_offered(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();
        $draft = $scenario->publishedTemplateVersion(published: false);

        $this->getJson('/api/v1/templates/'.$draft->template->public_id, NativeApiScenario::headers($issued))
            ->assertOk()
            ->assertJsonPath('current_version', null)
            ->assertJsonPath('versions', []);
    }

    public function test_a_template_response_carries_no_storage_key(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();
        $version = $scenario->publishedTemplateVersion();

        $body = (string) $this->getJson('/api/v1/templates/'.$version->template->public_id,
            NativeApiScenario::headers($issued))->getContent();

        $this->assertStringNotContainsString($scenario->signing->revision->path, $body);
        $this->assertStringNotContainsString($scenario->signing->revision->disk, $body);
    }

    public function test_the_list_pages_with_a_cursor(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();

        for ($i = 1; $i <= 3; $i++) {
            $scenario->publishedTemplateVersion(name: 'Template '.$i);
        }

        $first = $this->getJson('/api/v1/templates?limit=2', NativeApiScenario::headers($issued))->assertOk();

        $this->assertCount(2, $first->json('data'));
        $this->assertNotNull($first->json('meta.next_cursor'));

        $second = $this->getJson('/api/v1/templates?limit=2&cursor='.$first->json('meta.next_cursor'),
            NativeApiScenario::headers($issued))->assertOk();

        $this->assertCount(1, $second->json('data'));
        $this->assertNull($second->json('meta.next_cursor'));

        // No row appears on two pages and none is skipped.
        $ids = array_merge(
            array_column($first->json('data'), 'id'),
            array_column($second->json('data'), 'id'),
        );
        $this->assertCount(3, array_unique($ids));
    }

    public function test_a_limit_above_the_maximum_is_clamped_rather_than_refused(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();
        $scenario->publishedTemplateVersion();

        $this->getJson('/api/v1/templates?limit='.(Page::MAX_LIMIT + 500), NativeApiScenario::headers($issued))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_a_cursor_this_api_did_not_issue_is_refused(): void
    {
        $scenario = NativeApiScenario::create();

        $this->getJson('/api/v1/templates?cursor=notacursor', NativeApiScenario::headers($scenario->credential()))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_cursor');
    }

    public function test_an_unknown_template_is_a_404(): void
    {
        $scenario = NativeApiScenario::create();

        $this->getJson('/api/v1/templates/01JC0000000000000000000001',
            NativeApiScenario::headers($scenario->credential()))
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }
}
