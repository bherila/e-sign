<?php

declare(strict_types=1);

namespace Tests\Feature\Integration\Native;

use App\Domain\Delivery\Outbound\DestinationPolicy;
use App\Domain\Delivery\Outbound\HostResolver;
use App\Domain\Delivery\Webhooks\Models\WebhookEndpoint;
use App\Domain\Delivery\Webhooks\OutboxWriter;
use App\Domain\Delivery\Webhooks\WebhookEndpointManager;
use App\Domain\Signing\Models\Envelope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeHostResolver;
use Tests\Support\NativeApiScenario;
use Tests\Support\SigningFixtures;
use Tests\TestCase;

/**
 * Cross-workspace isolation over the native API.
 *
 * The HTTP half of the property tests/Feature/Identity/CrossWorkspaceIsolationTest.php
 * describes, for this surface. A credential belongs to exactly one workspace, and there is no
 * workspace parameter anywhere in `/api/v1` — so the only way a tenant boundary can be
 * crossed here is a lookup written the wrong way round: fetch by id, compare the workspace
 * afterwards.
 *
 * Every case therefore asserts **404, not 403**. A 403 would confirm the id exists, which is
 * the leak docs/HANDOFF.md section 10 rules out ("identifiers and foreign keys must not
 * bypass scope"): the caller holds every scope the route asks for, so a 403 could only mean
 * "this belongs to somebody else".
 */
class NativeApiIsolationTest extends TestCase
{
    use RefreshDatabase;

    private NativeApiScenario $alpha;

    private NativeApiScenario $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = NativeApiScenario::create();
        $this->beta = NativeApiScenario::create();

        $this->app->instance(HostResolver::class, new FakeHostResolver([
            'receiver.example.test' => ['198.51.100.20'],
        ]));
        $this->app->forgetInstance(DestinationPolicy::class);
    }

    public function test_another_workspaces_envelope_is_absent_on_every_envelope_route(): void
    {
        $issued = $this->alpha->credential();
        $foreign = $this->beta->signing->preparedDraft();

        foreach ([
            ['GET', ''],
            ['PATCH', ''],
            ['POST', '/send'],
            ['POST', '/cancel'],
            ['GET', '/recipients'],
            ['GET', '/values'],
            ['GET', '/events'],
            ['GET', '/artifacts'],
            ['GET', '/artifacts/anything/download'],
        ] as [$method, $suffix]) {
            $this->json($method, '/api/v1/envelopes/'.$foreign->public_id.$suffix, [],
                NativeApiScenario::headers($issued))
                ->assertNotFound()
                ->assertJsonPath('error.code', 'not_found');
        }

        // And nothing was done to it on the way to saying no.
        $this->assertSame('draft', $foreign->refresh()->state->value);
    }

    public function test_another_workspaces_template_and_version_are_absent(): void
    {
        $issued = $this->alpha->credential();
        $foreign = $this->beta->publishedTemplateVersion();

        $this->getJson('/api/v1/templates/'.$foreign->template->public_id, NativeApiScenario::headers($issued))
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');

        $this->getJson('/api/v1/templates', NativeApiScenario::headers($issued))
            ->assertOk()
            ->assertJsonPath('data', []);

        // A version id from another tenant cannot be used to build an envelope either, and
        // the answer is the same absence rather than a permission error.
        $this->postJson('/api/v1/envelopes', ['template_version_id' => $foreign->public_id],
            NativeApiScenario::headers($issued))
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');

        $this->assertSame(0, Envelope::query()->where('workspace_id', $this->alpha->workspace->getKey())->count());
    }

    public function test_another_workspaces_document_cannot_become_an_envelope(): void
    {
        $issued = $this->alpha->credential();

        $this->postJson('/api/v1/envelopes', [
            'document_id' => $this->beta->signing->document->public_id,
            'field_schema' => SigningFixtures::singleSigner(),
        ], NativeApiScenario::headers($issued))
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_another_workspaces_webhook_endpoint_is_absent(): void
    {
        $issued = $this->alpha->credential();

        [$foreign] = app(WebhookEndpointManager::class)->create(
            $this->beta->workspace,
            'https://receiver.example.test/hooks/beta',
        );

        $this->getJson('/api/v1/webhooks/endpoints', NativeApiScenario::headers($issued))
            ->assertOk()
            ->assertJsonPath('data', []);

        foreach ([
            ['PATCH', ''],
            ['DELETE', ''],
            ['POST', '/rotate-secret'],
        ] as [$method, $suffix]) {
            $this->json($method, '/api/v1/webhooks/endpoints/'.$foreign->public_id.$suffix, [],
                NativeApiScenario::headers($issued))
                ->assertNotFound()
                ->assertJsonPath('error.code', 'not_found');
        }

        $this->assertTrue(WebhookEndpoint::query()->findOrFail($foreign->getKey())->isEnabled());
    }

    public function test_another_workspaces_events_are_never_in_the_feed(): void
    {
        Queue::fake();

        $issued = $this->alpha->credential();
        $mine = $this->alpha->signing->preparedDraft();
        $foreign = $this->beta->signing->preparedDraft();

        // The same envelope id in two workspaces is impossible, but an attacker controls the
        // payload of nothing here; what is asserted is that the workspace predicate runs
        // before the payload is compared at all.
        DB::transaction(fn () => app(OutboxWriter::class)->record(
            $this->beta->workspace,
            'signing_request.created',
            ['envelope' => $foreign->public_id],
        ));

        $this->getJson('/api/v1/envelopes/'.$mine->public_id.'/events', NativeApiScenario::headers($issued))
            ->assertOk()
            ->assertJsonPath('data', []);

        $this->getJson('/api/v1/envelopes/'.$foreign->public_id.'/events', NativeApiScenario::headers($issued))
            ->assertNotFound();
    }

    public function test_me_never_reports_another_workspace(): void
    {
        $issued = $this->alpha->credential();

        $this->getJson('/api/v1/me', NativeApiScenario::headers($issued))
            ->assertOk()
            ->assertJsonPath('workspace.id', $this->alpha->workspace->public_id);
    }
}
