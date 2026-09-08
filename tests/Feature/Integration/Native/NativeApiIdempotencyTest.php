<?php

declare(strict_types=1);

namespace Tests\Feature\Integration\Native;

use App\Domain\Integration\Native\IdempotencyStore;
use App\Domain\Integration\Native\Models\IdempotencyKey;
use App\Domain\Signing\Models\Envelope;
use App\Http\Middleware\Api\ApiErrorBoundary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\NativeApiScenario;
use Tests\TestCase;

/**
 * `Idempotency-Key`.
 *
 * A dropped connection is not evidence that a request did not happen. Without this header a
 * client that retries a `POST /envelopes` sends the same agreement to the same people twice
 * and cannot afterwards tell which of the two a signer used. The property under test is
 * exactly that: **one envelope, whatever the network did.**
 */
class NativeApiIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_retry_with_the_same_key_replays_the_first_response_and_creates_nothing(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();
        $version = $scenario->publishedTemplateVersion();

        $body = ['template_version_id' => $version->public_id, 'title' => 'Retryable NDA'];
        $headers = NativeApiScenario::headers($issued, ['Idempotency-Key' => 'client-key-1']);

        $first = $this->postJson('/api/v1/envelopes', $body, $headers)->assertCreated();
        $second = $this->postJson('/api/v1/envelopes', $body, $headers)->assertCreated();

        $this->assertSame($first->getContent(), $second->getContent());
        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame(1, Envelope::query()->count());

        // A replay says so, so a client can tell it from a fresh result without diffing.
        $this->assertNull($first->headers->get(ApiErrorBoundary::REPLAY_HEADER));
        $this->assertSame('true', $second->headers->get(ApiErrorBoundary::REPLAY_HEADER));
    }

    public function test_key_order_in_the_body_does_not_defeat_a_replay(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();
        $version = $scenario->publishedTemplateVersion();

        $headers = NativeApiScenario::headers($issued, ['Idempotency-Key' => 'client-key-1']);

        $first = $this->postJson('/api/v1/envelopes',
            ['template_version_id' => $version->public_id, 'title' => 'Retryable NDA'], $headers)->assertCreated();

        // The same request, serialised by a client that iterates its map differently.
        $second = $this->postJson('/api/v1/envelopes',
            ['title' => 'Retryable NDA', 'template_version_id' => $version->public_id], $headers)->assertCreated();

        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame(1, Envelope::query()->count());
    }

    public function test_the_same_key_with_a_different_request_is_refused(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();
        $version = $scenario->publishedTemplateVersion();

        $headers = NativeApiScenario::headers($issued, ['Idempotency-Key' => 'client-key-1']);

        $this->postJson('/api/v1/envelopes',
            ['template_version_id' => $version->public_id, 'title' => 'First'], $headers)->assertCreated();

        $this->postJson('/api/v1/envelopes',
            ['template_version_id' => $version->public_id, 'title' => 'Second'], $headers)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'idempotency_key_reused');

        // Refused, not answered with the first call's envelope: the client believes it made
        // a second, different request, and silently returning the first would be a no-op.
        $this->assertSame(1, Envelope::query()->count());
    }

    public function test_keys_are_scoped_to_the_credential_that_presented_them(): void
    {
        $scenario = NativeApiScenario::create();
        $version = $scenario->publishedTemplateVersion();

        $one = $scenario->credential(label: 'integration one');
        $two = $scenario->credential(label: 'integration two');

        $body = ['template_version_id' => $version->public_id, 'title' => 'Shared key name'];

        $first = $this->postJson('/api/v1/envelopes', $body,
            NativeApiScenario::headers($one, ['Idempotency-Key' => 'shared']))->assertCreated();

        // Two integrations in one workspace generate keys independently. A collision between
        // them must not make one replay the other's response.
        $second = $this->postJson('/api/v1/envelopes', $body,
            NativeApiScenario::headers($two, ['Idempotency-Key' => 'shared']))->assertCreated();

        $this->assertNotSame($first->json('id'), $second->json('id'));
        $this->assertSame(2, Envelope::query()->count());
    }

    public function test_a_key_expires_after_its_window_and_the_retry_is_a_real_attempt(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();
        $version = $scenario->publishedTemplateVersion();

        $body = ['template_version_id' => $version->public_id, 'title' => 'Retryable NDA'];
        $headers = NativeApiScenario::headers($issued, ['Idempotency-Key' => 'client-key-1']);

        $first = $this->postJson('/api/v1/envelopes', $body, $headers)->assertCreated();

        $this->travel(IdempotencyStore::TTL_HOURS + 1)->hours();

        $second = $this->postJson('/api/v1/envelopes', $body, $headers)->assertCreated();

        // Past the window the key is not a record of anything, so this is a new envelope
        // rather than a silent replay of day-old content.
        $this->assertNotSame($first->json('id'), $second->json('id'));
        $this->assertSame(2, Envelope::query()->count());
    }

    public function test_a_failed_request_does_not_freeze_its_error_under_the_key(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();
        $version = $scenario->publishedTemplateVersion();

        $headers = NativeApiScenario::headers($issued, ['Idempotency-Key' => 'client-key-1']);

        // A request the client will fix and retry with the same key.
        $this->postJson('/api/v1/envelopes',
            ['template_version_id' => $version->public_id, 'assurance_level' => 'nonsense'], $headers)
            ->assertStatus(422);

        $this->assertSame(0, IdempotencyKey::query()->count());

        $this->postJson('/api/v1/envelopes',
            ['template_version_id' => $version->public_id, 'assurance_level' => 'pades-b-b'], $headers)
            ->assertCreated();
    }

    public function test_a_key_is_honoured_on_patch_send_and_cancel(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();
        $version = $scenario->publishedTemplateVersion();

        $envelope = (string) $this->postJson('/api/v1/envelopes', [
            'template_version_id' => $version->public_id,
            'values' => ['agreement_effective_date' => '2026-02-01'],
        ], NativeApiScenario::headers($issued))->assertCreated()->json('id');

        $sendHeaders = NativeApiScenario::headers($issued, ['Idempotency-Key' => 'send-1']);

        $this->postJson('/api/v1/envelopes/'.$envelope.'/send', [], $sendHeaders)->assertOk();

        // Without the key this second call is `409 illegal_transition`. With it, it replays.
        $replay = $this->postJson('/api/v1/envelopes/'.$envelope.'/send', [], $sendHeaders)->assertOk();

        $this->assertSame('true', $replay->headers->get(ApiErrorBoundary::REPLAY_HEADER));
        $this->assertSame('sent', $replay->json('state'));
    }

    public function test_no_key_means_no_replay(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();
        $version = $scenario->publishedTemplateVersion();

        $body = ['template_version_id' => $version->public_id];

        $this->postJson('/api/v1/envelopes', $body, NativeApiScenario::headers($issued))->assertCreated();
        $this->postJson('/api/v1/envelopes', $body, NativeApiScenario::headers($issued))->assertCreated();

        $this->assertSame(2, Envelope::query()->count());
        $this->assertSame(0, IdempotencyKey::query()->count());
    }

    public function test_an_overlong_key_is_refused(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();
        $version = $scenario->publishedTemplateVersion();

        $this->postJson('/api/v1/envelopes', ['template_version_id' => $version->public_id],
            NativeApiScenario::headers($issued, [
                'Idempotency-Key' => str_repeat('k', IdempotencyStore::MAX_KEY_LENGTH + 1),
            ]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_a_claim_left_in_flight_answers_conflict_rather_than_running_twice(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();
        $version = $scenario->publishedTemplateVersion();

        // What a concurrent request sees: the row exists, the first attempt has not finished.
        IdempotencyKey::query()->create([
            'credential_id' => $issued->credential->getKey(),
            'key' => 'client-key-1',
            'request_hash' => IdempotencyStore::hashRequest(
                'POST',
                'api/v1/envelopes',
                (string) json_encode(['template_version_id' => $version->public_id]),
            ),
        ]);

        $this->postJson('/api/v1/envelopes', ['template_version_id' => $version->public_id],
            NativeApiScenario::headers($issued, ['Idempotency-Key' => 'client-key-1']))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'idempotency_key_in_flight');

        $this->assertSame(0, Envelope::query()->count());
    }

    public function test_the_prune_command_removes_keys_past_their_window(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();
        $version = $scenario->publishedTemplateVersion();

        $this->postJson('/api/v1/envelopes', ['template_version_id' => $version->public_id],
            NativeApiScenario::headers($issued, ['Idempotency-Key' => 'client-key-1']))->assertCreated();

        $this->assertSame(1, IdempotencyKey::query()->count());

        $this->artisan('esign:api:prune-idempotency-keys')->assertSuccessful();
        $this->assertSame(1, IdempotencyKey::query()->count());

        $this->travel(IdempotencyStore::TTL_HOURS + 1)->hours();

        $this->artisan('esign:api:prune-idempotency-keys')->assertSuccessful();
        $this->assertSame(0, IdempotencyKey::query()->count());
    }
}
