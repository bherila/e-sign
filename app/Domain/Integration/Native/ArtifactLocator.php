<?php

declare(strict_types=1);

namespace App\Domain\Integration\Native;

use App\Domain\Signing\Models\Envelope;

/**
 * How an HTTP surface finds the finished artifacts of a completed envelope.
 *
 * This is a seam, deliberately narrow, and it exists because the artifact table lands in a
 * different change from the API that serves it. The native API is written against this
 * interface and ships with {@see NoArtifactsYetLocator}, which finds nothing; when
 * finalization lands it binds its own implementation in a service provider and every
 * artifact route starts working with no controller edit.
 *
 * ## What an implementation must guarantee
 *
 * 1. **Tenancy is already decided.** The caller resolved `$envelope` inside the principal's
 *    workspace before calling. An implementation must not widen that: it returns artifacts
 *    of *this* envelope and nothing else, and it must not accept an artifact id as a key in
 *    its own right (see `find()`).
 * 2. **Only retrievable artifacts.** An artifact is listed only once its bytes are durably
 *    stored and readable. Listing one that finalization has not finished writing would let
 *    the API answer 200 with a truncated body, which is the "successful no-op" AGENTS.md
 *    forbids; until then, return an empty list and the API answers `409 not_completed`.
 * 3. **Bytes stream, never presign.** {@see LocatedArtifact::open()} returns a stream the
 *    application copies to the client (docs/BLOB_STORAGE.md rule 1). No implementation may
 *    return a storage URL, and no disk name or object path may appear on a
 *    {@see LocatedArtifact}.
 * 4. **Digests are recorded, not computed on read.** `sha256` is what was written and
 *    validated at finalization, so a caller can compare it against the completion event.
 */
interface ArtifactLocator
{
    /**
     * Every retrievable artifact of this envelope, oldest first.
     *
     * An empty list is the honest answer for an envelope that has not completed, for one
     * whose finalization failed, and for one whose artifacts are still being written. The
     * API does not distinguish them to the client beyond the envelope's own state.
     *
     * @return list<LocatedArtifact>
     */
    public function forEnvelope(Envelope $envelope): array;

    /**
     * One artifact of this envelope by its public id, or null.
     *
     * The envelope is a parameter and not an optimisation: looking an artifact up by its id
     * alone and comparing the envelope afterwards is the cross-tenant read pattern
     * docs/HANDOFF.md section 10 rules out. Null means "not an artifact of this envelope",
     * whether or not that id exists elsewhere.
     */
    public function find(Envelope $envelope, string $artifactId): ?LocatedArtifact;
}
