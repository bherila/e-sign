<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Evidence\Finalization\Artifacts\ArtifactStorageKey;
use App\Domain\Evidence\Finalization\Artifacts\ArtifactStore;
use Closure;

/**
 * A real {@see ArtifactStore} that also writes every operation into a shared log, and can run
 * a callback in the middle of one.
 *
 * Two things need observing that no assertion after the fact can see.
 *
 * **Ordering.** "A completion event is published only after the final PDF is generated,
 * validated, durably stored, and retrievable" (AGENTS.md) is a statement about *sequence*, and
 * a test that checks the end state cannot distinguish a correct implementation from one that
 * completes first and stores afterwards. Sharing one log between this store and
 * {@see LoggingEnvelopeEventSink} makes the sequence itself assertable.
 *
 * **Interleaving.** A second finalization attempt, or a cancellation, has to happen *while* the
 * first attempt is between its two transactions — which is precisely when it holds no lock.
 * `$during` runs there, so the race is real rather than described.
 */
final class RecordingArtifactStore implements ArtifactStore
{
    /** @var list<string> */
    public array $log;

    private int $puts = 0;

    /**
     * @param  list<string>  $log  Shared with the event sink so one sequence covers both.
     * @param  Closure(int): void|null  $during  Run after the nth successful put, given the count.
     */
    public function __construct(
        private readonly ArtifactStore $inner,
        array &$log = [],
        private readonly ?Closure $during = null,
        private readonly ?int $afterPut = null,
    ) {
        $this->log = &$log;
    }

    public function putVerified(string $disk, ArtifactStorageKey $key, string $bytes, string $sha256): void
    {
        $this->inner->putVerified($disk, $key, $bytes, $sha256);

        $this->puts++;
        $this->log[] = 'stored:'.$key->value;

        // An independent read-back at this instant. The store under test already did one —
        // that is what putVerified() returning means — but doing it again here puts a
        // *witnessed* "these bytes were retrievable" entry in the log, so the ordering
        // assertion rests on something the test observed rather than on trust.
        $this->log[] = 'read-back:'.$this->inner->digestOf($disk, $key->value);

        if ($this->during instanceof Closure && $this->afterPut === $this->puts) {
            ($this->during)($this->puts);
        }
    }

    public function digestOf(string $disk, string $path): string
    {
        return $this->inner->digestOf($disk, $path);
    }

    public function exists(string $disk, string $path): bool
    {
        return $this->inner->exists($disk, $path);
    }

    public function get(string $disk, string $path): string
    {
        return $this->inner->get($disk, $path);
    }

    public function readStream(string $disk, string $path)
    {
        return $this->inner->readStream($disk, $path);
    }

    public function listWithTimestamps(string $disk, string $prefix): array
    {
        return $this->inner->listWithTimestamps($disk, $prefix);
    }

    public function delete(string $disk, string $path): void
    {
        $this->inner->delete($disk, $path);
        $this->log[] = 'deleted:'.$path;
    }
}
