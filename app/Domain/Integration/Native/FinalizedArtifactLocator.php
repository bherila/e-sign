<?php

declare(strict_types=1);

namespace App\Domain\Integration\Native;

use App\Domain\Evidence\Finalization\Artifacts\Artifact;
use App\Domain\Evidence\Finalization\Artifacts\ArtifactKind;
use App\Domain\Evidence\Finalization\Artifacts\ArtifactStore;
use App\Domain\Signing\Models\Envelope;
use Illuminate\Database\Eloquent\Collection;

/**
 * The real {@see ArtifactLocator}: the `artifacts` table.
 *
 * {@see NoArtifactsYetLocator} was the honest answer while finalization did not exist. It
 * does now (issue #31), so this is bound over it in App\Providers\IntegrationServiceProvider
 * and every artifact route on both HTTP surfaces starts returning bytes with no controller
 * edit — which was the point of the seam.
 *
 * ## What makes a row eligible
 *
 * `published_at is not null`, and nothing else. A row without it names bytes that were
 * written but that no completion has been asserted over
 * (`database/migrations/..._create_artifacts_table.php`), and listing one would let the API
 * answer 200 for an execution the state machine has not accepted — the successful no-op
 * AGENTS.md forbids. Unpublished rows are therefore invisible here rather than filtered
 * later, so no caller of this class can forget the check.
 *
 * ## Digests are read, never recomputed
 *
 * `sha256` is the digest finalization computed over the bytes and then *re-read through the
 * storage adapter* to confirm before writing the row. Recomputing it here would replace a
 * recorded fact with a fresh measurement of whatever is on the disk today, which is the one
 * thing a caller comparing against the completion event needs it not to be.
 *
 * ## Bytes
 *
 * {@see LocatedArtifact::open()} owes a readable stream, so the closure goes through
 * {@see ArtifactStore::readStream()} — the same port
 * App\Domain\Evidence\Finalization\ArtifactDownloader reads, on every driver, with nothing
 * presigned and no disk name or object path on the returned value
 * (docs/BLOB_STORAGE.md rule 1). The facade's own download route uses `ArtifactDownloader`
 * directly, because there it owes a whole response — content type fixed by artifact kind,
 * `nosniff`, an ASCII filename derived from the envelope title — rather than a stream. Both
 * paths read the same rows and the same objects; only the framing differs.
 */
final readonly class FinalizedArtifactLocator implements ArtifactLocator
{
    public function __construct(private ArtifactStore $store) {}

    /**
     * @return list<LocatedArtifact>
     */
    public function forEnvelope(Envelope $envelope): array
    {
        return array_map(
            fn (Artifact $artifact): LocatedArtifact => $this->describe($artifact, $envelope),
            $this->published($envelope)->all(),
        );
    }

    public function find(Envelope $envelope, string $artifactId): ?LocatedArtifact
    {
        // Constrained by envelope before the id is compared, for the reason the interface
        // spells out: looking an artifact up by id alone and checking the envelope afterwards
        // is the cross-tenant read docs/HANDOFF.md section 10 rules out.
        $artifact = Artifact::query()
            ->where('envelope_id', $envelope->getKey())
            ->whereNotNull('published_at')
            ->where('public_id', $artifactId)
            ->first();

        return $artifact instanceof Artifact ? $this->describe($artifact, $envelope) : null;
    }

    /**
     * One published artifact of this envelope by kind, or null.
     *
     * Not on the interface: the native API addresses artifacts by opaque id, and only the
     * compatibility facade needs "the executed PDF of this agreement" as a question, because
     * its `/download` route promises exactly those bytes and no others.
     */
    public function ofKind(Envelope $envelope, ArtifactKind $kind): ?Artifact
    {
        $artifact = Artifact::query()
            ->where('envelope_id', $envelope->getKey())
            ->whereNotNull('published_at')
            ->where('kind', $kind->value)
            // `artifacts` has a unique index on (envelope_id, kind), so there is at most one
            // row today. Ordered anyway: if a re-finalization ever relaxes that, three
            // callers of this method picking different rows on different reads would be a
            // very quiet bug, and the sibling `published()` orders for the same reason.
            ->orderBy('id')
            ->first();

        return $artifact instanceof Artifact ? $artifact : null;
    }

    /**
     * @return Collection<int, Artifact>
     */
    private function published(Envelope $envelope): Collection
    {
        /** @var Collection<int, Artifact> $rows */
        $rows = Artifact::query()
            ->where('envelope_id', $envelope->getKey())
            ->whereNotNull('published_at')
            // Oldest first, as the interface requires. `id` rather than `created_at`,
            // because three artifacts of one publication share a timestamp to the second and
            // a stable order is what a client diffing two reads depends on.
            ->orderBy('id')
            ->get();

        return $rows;
    }

    private function describe(Artifact $artifact, Envelope $envelope): LocatedArtifact
    {
        $disk = $artifact->disk;
        $path = $artifact->path;
        $store = $this->store;

        return new LocatedArtifact(
            id: $artifact->public_id,
            kind: $artifact->kind->value,
            // Built from the envelope title, never from anything an uploader or a signer
            // supplied. The envelope is already in hand, so this costs no query.
            filename: $artifact->downloadFilename((string) $envelope->title),
            contentType: $artifact->kind->contentType(),
            sha256: $artifact->sha256,
            bytes: $artifact->bytes,
            createdAt: $artifact->created_at,
            stream: static fn () => $store->readStream($disk, $path),
        );
    }
}
