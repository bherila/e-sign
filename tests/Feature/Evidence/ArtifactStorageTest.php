<?php

declare(strict_types=1);

namespace Tests\Feature\Evidence;

use App\Domain\Evidence\Finalization\Artifacts\ArtifactKind;
use App\Domain\Evidence\Finalization\Artifacts\ArtifactStorageKey;
use App\Domain\Evidence\Finalization\Artifacts\ArtifactStore;
use App\Domain\Evidence\Finalization\EnvelopeFinalizer;
use App\Domain\Evidence\Finalization\Exceptions\ArtifactStorageException;
use App\Domain\Evidence\Finalization\Jobs\FinalizeEnvelope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Support\FinalizationScenario;
use Tests\TestCase;

/**
 * The storage layer publication rests on: the key layout, and the read-back that turns "the
 * driver accepted the bytes" into "the bytes are retrievable".
 */
class ArtifactStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_key_is_content_addressed_and_scoped_by_workspace_and_envelope(): void
    {
        $workspace = (string) Str::ulid();
        $envelope = (string) Str::ulid();
        $digest = hash('sha256', 'bytes');

        $key = ArtifactStorageKey::for($workspace, $envelope, ArtifactKind::ExecutedPdf, $digest);

        $this->assertSame(
            "envelopes/{$workspace}/{$envelope}/executed_pdf-{$digest}.pdf",
            $key->value,
        );
        $this->assertSame(
            "envelopes/{$workspace}/{$envelope}",
            ArtifactStorageKey::envelopePrefix($workspace, $envelope),
        );
        $this->assertStringEndsWith(
            '.json',
            ArtifactStorageKey::for($workspace, $envelope, ArtifactKind::EvidenceJson, $digest)->value,
        );

        // Different bytes are a different key, so nothing can be overwritten.
        $this->assertNotSame(
            $key->value,
            ArtifactStorageKey::for($workspace, $envelope, ArtifactKind::ExecutedPdf, hash('sha256', 'other'))->value,
        );
    }

    public function test_a_key_refuses_an_identifier_that_is_not_a_usable_segment(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ArtifactStorageKey::for('../etc', (string) Str::ulid(), ArtifactKind::ExecutedPdf, hash('sha256', 'x'));
    }

    public function test_a_key_refuses_anything_that_is_not_a_hex_sha256(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ArtifactStorageKey::for((string) Str::ulid(), (string) Str::ulid(), ArtifactKind::ExecutedPdf, 'not-a-digest');
    }

    /**
     * A write that reads back as something else is a storage failure, not a success.
     *
     * An S3-compatible store can accept a write that is then unreadable, and a full or
     * read-only local volume can truncate. This is the check that stops a database row from
     * pointing at bytes nobody can retrieve.
     */
    public function test_a_write_that_does_not_read_back_is_refused(): void
    {
        Storage::fake('documents');

        $store = app(ArtifactStore::class);
        $key = ArtifactStorageKey::for(
            (string) Str::ulid(),
            (string) Str::ulid(),
            ArtifactKind::EvidenceJson,
            hash('sha256', '{}'),
        );

        $store->putVerified('documents', $key, '{}', hash('sha256', '{}'));
        $this->assertTrue($store->exists('documents', $key->value));

        $this->expectException(ArtifactStorageException::class);
        $this->expectExceptionMessage('read back as');

        // The same key, a digest that does not describe the bytes.
        $store->putVerified('documents', $key, '{}', hash('sha256', 'different'));
    }

    public function test_reading_a_missing_object_is_a_typed_failure(): void
    {
        Storage::fake('documents');

        $this->expectException(ArtifactStorageException::class);

        app(ArtifactStore::class)->digestOf('documents', 'envelopes/nothing/here.pdf');
    }

    public function test_the_job_finalizes_the_envelope_it_names(): void
    {
        $scenario = FinalizationScenario::signed();

        // Carried by public id, not as a serialized model: the row will have moved by the
        // time the job runs.
        $job = new FinalizeEnvelope($scenario->envelope->public_id);

        $this->assertSame(1, $job->tries);
        $this->assertSame($scenario->envelope->public_id, $job->uniqueId());

        $job->handle(app(EnvelopeFinalizer::class));

        $this->assertSame('completed', $scenario->envelope->refresh()->state->value);
    }

    public function test_the_job_can_be_queued(): void
    {
        Queue::fake();

        FinalizeEnvelope::dispatch('01JSYNTHETICENVELOPEID0000');

        Queue::assertPushed(FinalizeEnvelope::class);
    }
}
