<?php

declare(strict_types=1);

namespace Tests\Feature\Integration\Native;

use App\Domain\Integration\Native\ArtifactLocator;
use App\Domain\Integration\Native\FinalizedArtifactLocator;
use App\Domain\Integration\Native\NoArtifactsYetLocator;
use App\Domain\Signing\Envelopes\EnvelopeState;
use App\Domain\Signing\Models\Envelope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\NativeApiScenario;
use Tests\Support\RecordedArtifactLocator;
use Tests\TestCase;

/**
 * Artifact listing and download, on both sides of the {@see ArtifactLocator} seam.
 *
 * The seam is why this API can ship before finalization does. What matters is that the two
 * refusals stay distinguishable — an envelope that has not completed is a fact about the
 * envelope, a completed envelope with no artifact is a fact about this build — and that
 * neither is ever a `200` with an empty list.
 */
class NativeApiArtifactTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The shipped locator is the real one now that finalization exists (issue #31).
     *
     * {@see NoArtifactsYetLocator} is kept and still honest — it is what a deployment with
     * finalization switched off would bind, and the empty side of the seam is what the two
     * refusals below are about — but it is no longer the default.
     */
    public function test_the_shipped_locator_reads_the_artifacts_table(): void
    {
        $this->assertInstanceOf(FinalizedArtifactLocator::class, app(ArtifactLocator::class));
    }

    public function test_an_envelope_that_has_not_completed_answers_not_completed(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();
        $envelope = $scenario->signing->sent();

        $this->getJson('/api/v1/envelopes/'.$envelope->public_id.'/artifacts', NativeApiScenario::headers($issued))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'not_completed')
            ->assertJsonPath('error.details.state', 'sent');
    }

    public function test_a_completed_envelope_with_no_published_artifact_answers_unsupported(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();
        $this->app->instance(ArtifactLocator::class, new NoArtifactsYetLocator);
        $envelope = $this->completed($scenario);

        // Deliberately not `409 not_completed`: the envelope *has* completed, and telling a
        // caller otherwise would be a lie about their agreement. Nor `200 []`, which would
        // say a completed agreement has no PDF.
        $this->getJson('/api/v1/envelopes/'.$envelope->public_id.'/artifacts', NativeApiScenario::headers($issued))
            ->assertStatus(501)
            ->assertJsonPath('error.code', 'unsupported')
            ->assertJsonPath('error.details.envelope_state', 'completed');
    }

    public function test_artifacts_are_listed_once_a_locator_finds_them(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();
        $envelope = $this->completed($scenario);

        $locator = new RecordedArtifactLocator;
        $artifact = $locator->add($envelope, '%PDF-1.7 synthetic sealed bytes');
        $this->app->instance(ArtifactLocator::class, $locator);

        $this->getJson('/api/v1/envelopes/'.$envelope->public_id.'/artifacts', NativeApiScenario::headers($issued))
            ->assertOk()
            ->assertJsonPath('data.0.id', 'artifact-1')
            ->assertJsonPath('data.0.kind', 'sealed_pdf')
            ->assertJsonPath('data.0.sha256', $artifact->sha256)
            ->assertJsonPath('data.0.bytes', $artifact->bytes)
            ->assertJsonPath(
                'data.0.download',
                '/api/v1/envelopes/'.$envelope->public_id.'/artifacts/artifact-1/download',
            )
            ->assertJsonPath('meta.next_cursor', null);
    }

    public function test_an_artifact_streams_through_the_application_with_its_digest(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();
        $envelope = $this->completed($scenario);

        $bytes = '%PDF-1.7 synthetic sealed bytes';
        $locator = new RecordedArtifactLocator;
        $artifact = $locator->add($envelope, $bytes);
        $this->app->instance(ArtifactLocator::class, $locator);

        $response = $this->get(
            '/api/v1/envelopes/'.$envelope->public_id.'/artifacts/artifact-1/download',
            NativeApiScenario::headers($issued),
        );

        $response->assertOk();
        $this->assertSame($bytes, $response->streamedContent());
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame($artifact->sha256, $response->headers->get('X-Artifact-Sha256'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_an_artifact_of_another_envelope_is_not_found(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();
        $envelope = $this->completed($scenario);

        $locator = new RecordedArtifactLocator;
        $locator->add($envelope, 'bytes', id: 'artifact-1');
        $this->app->instance(ArtifactLocator::class, $locator);

        $this->getJson('/api/v1/envelopes/'.$envelope->public_id.'/artifacts/artifact-9/download',
            NativeApiScenario::headers($issued))
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }

    private function completed(NativeApiScenario $scenario): Envelope
    {
        $signing = $scenario->signing;
        $envelope = $signing->sent();

        foreach (['buyer', 'seller'] as $index => $recipientId) {
            $signing->signAs($envelope->refresh(), $signing->recipient($envelope, $recipientId), 'session-'.$index);
        }

        $signing->machine()->markCompleted($envelope->refresh(), 'artifacts/synthetic-sealed.pdf');
        $envelope->refresh();

        $this->assertSame(EnvelopeState::Completed, $envelope->state);

        return $envelope;
    }
}
