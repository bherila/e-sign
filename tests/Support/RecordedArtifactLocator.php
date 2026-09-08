<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Integration\Native\ArtifactLocator;
use App\Domain\Integration\Native\LocatedArtifact;
use App\Domain\Signing\Models\Envelope;
use Carbon\CarbonImmutable;

/**
 * An {@see ArtifactLocator} backed by bytes held in memory.
 *
 * Stands in for the finalization module until it lands, so the artifact routes can be tested
 * against a locator that finds something as well as against the shipped one that finds
 * nothing. It is also the worked example of what an implementation owes: artifacts are keyed
 * by envelope, `find()` refuses an id that belongs to a different envelope, and the stream is
 * opened lazily rather than the bytes being handed over.
 *
 * All data is synthetic (AGENTS.md).
 */
final class RecordedArtifactLocator implements ArtifactLocator
{
    /** @var array<int, list<array{artifact: LocatedArtifact, bytes: string}>> */
    private array $byEnvelope = [];

    public function add(
        Envelope $envelope,
        string $bytes,
        string $id = 'artifact-1',
        string $kind = 'sealed_pdf',
        string $contentType = 'application/pdf',
    ): LocatedArtifact {
        $artifact = new LocatedArtifact(
            id: $id,
            kind: $kind,
            filename: $id.'.pdf',
            contentType: $contentType,
            sha256: hash('sha256', $bytes),
            bytes: strlen($bytes),
            createdAt: CarbonImmutable::now(),
            stream: static function () use ($bytes) {
                $stream = fopen('php://memory', 'r+');
                fwrite($stream, $bytes);
                rewind($stream);

                return $stream;
            },
        );

        $this->byEnvelope[$envelope->getKey()][] = ['artifact' => $artifact, 'bytes' => $bytes];

        return $artifact;
    }

    /**
     * @return list<LocatedArtifact>
     */
    public function forEnvelope(Envelope $envelope): array
    {
        return array_column($this->byEnvelope[$envelope->getKey()] ?? [], 'artifact');
    }

    public function find(Envelope $envelope, string $artifactId): ?LocatedArtifact
    {
        foreach ($this->forEnvelope($envelope) as $artifact) {
            if ($artifact->id === $artifactId) {
                return $artifact;
            }
        }

        return null;
    }
}
