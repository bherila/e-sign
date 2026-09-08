<?php

declare(strict_types=1);

namespace App\Domain\Integration\Native;

use App\Domain\Signing\Models\Envelope;

/**
 * The default {@see ArtifactLocator}: there are no artifacts, because finalization does not
 * produce any yet.
 *
 * This is not a stub that pretends. Finding nothing makes the artifact routes answer
 * `409 not_completed`, which is the truth for every envelope in a build with no finalizer —
 * a caller is told plainly that there is nothing to download, and never handed an empty or
 * placeholder PDF (AGENTS.md, "Fail closed").
 *
 * When finalization lands it binds its own implementation over this one in a service
 * provider. Nothing in app/Http changes.
 */
final class NoArtifactsYetLocator implements ArtifactLocator
{
    /**
     * @return list<LocatedArtifact>
     */
    public function forEnvelope(Envelope $envelope): array
    {
        return [];
    }

    public function find(Envelope $envelope, string $artifactId): ?LocatedArtifact
    {
        return null;
    }
}
