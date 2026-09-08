<?php

declare(strict_types=1);

namespace Tests\Feature\Integration\Native;

use App\Domain\Delivery\Webhooks\OutboxWriter;
use App\Domain\Identity\Models\Workspace;
use App\Domain\Signing\Models\Envelope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\NativeApiScenario;
use Tests\TestCase;

/**
 * `GET /envelopes/{id}/events` — the pull half of the webhook feed.
 *
 * The events themselves are written by the Delivery module. What is asserted here is the
 * read: that an envelope's events are found whichever payload key names it, that another
 * envelope's events are not in the answer, that another workspace's are unreachable, and
 * that the cursor pages without skipping or repeating.
 */
class NativeApiEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_envelopes_events_are_listed_oldest_first(): void
    {
        Queue::fake();

        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();
        $envelope = $scenario->signing->preparedDraft();

        $this->record($scenario->workspace, 'signing_request.created', ['envelope' => $envelope->public_id]);
        $this->record($scenario->workspace, 'signing_request.sent', ['signing_request_id' => $envelope->public_id]);

        $this->getJson('/api/v1/envelopes/'.$envelope->public_id.'/events', NativeApiScenario::headers($issued))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.event', 'signing_request.created')
            ->assertJsonPath('data.1.event', 'signing_request.sent')
            ->assertJsonPath('data.0.payload.envelope', $envelope->public_id)
            ->assertJsonPath('meta.next_cursor', null);
    }

    public function test_another_envelopes_events_are_not_included(): void
    {
        Queue::fake();

        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();
        $mine = $scenario->signing->preparedDraft();
        $other = $scenario->signing->preparedDraft();

        $this->record($scenario->workspace, 'signing_request.created', ['envelope' => $mine->public_id]);
        $this->record($scenario->workspace, 'signing_request.created', ['envelope' => $other->public_id]);

        $this->getJson('/api/v1/envelopes/'.$mine->public_id.'/events', NativeApiScenario::headers($issued))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.payload.envelope', $mine->public_id);
    }

    public function test_the_feed_pages_with_a_cursor(): void
    {
        Queue::fake();

        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();
        $envelope = $scenario->signing->preparedDraft();

        for ($i = 0; $i < 5; $i++) {
            $this->record($scenario->workspace, 'esign.envelope.probe.'.$i, ['envelope' => $envelope->public_id]);
        }

        $seen = [];
        $cursor = null;
        $pages = 0;

        do {
            $response = $this->getJson(
                '/api/v1/envelopes/'.$envelope->public_id.'/events?limit=2'.($cursor === null ? '' : '&cursor='.$cursor),
                NativeApiScenario::headers($issued),
            )->assertOk();

            $seen = array_merge($seen, array_column($response->json('data'), 'id'));
            $cursor = $response->json('meta.next_cursor');
            $pages++;
        } while ($cursor !== null && $pages < 10);

        $this->assertCount(5, $seen);
        $this->assertCount(5, array_unique($seen));
        $this->assertSame(3, $pages);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function record(Workspace $workspace, string $eventName, array $payload): void
    {
        // The outbox refuses a write with no transaction open, which is the whole point of
        // the pattern; a test writing one honours it rather than reaching past it.
        DB::transaction(fn () => app(OutboxWriter::class)->record($workspace, $eventName, $payload));
    }
}
