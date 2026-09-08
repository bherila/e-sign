<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Finalization\Artifacts;

use App\Domain\Evidence\Finalization\Exceptions\ArtifactStorageException;

/**
 * Where artifact bytes live, and the one guarantee finalization needs from it.
 *
 * The port exists for two reasons. The first is the ordinary one: the `local` and `s3`
 * drivers are both in use, and no code above this line may branch on which
 * (docs/BLOB_STORAGE.md). The second is the interesting one — publication has to be able to
 * *prove* an object is retrievable before a completion event is published, and a test has to
 * be able to observe that proof happening before the completion, not after. An interface is
 * what makes that observable.
 *
 * There is deliberately no `url()` and no `temporaryUrl()`. A presigned URL is a bearer
 * token, the `local` driver cannot mint one at all, and a signed artifact must never be
 * reachable except through a request that has already been authorized.
 */
interface ArtifactStore
{
    /**
     * Store bytes and confirm they read back with the expected digest.
     *
     * An implementation must re-read the object *through the storage adapter* and re-hash
     * it. A driver returning true means it accepted the bytes, not that they are
     * retrievable: an S3-compatible store can accept a write that is then unreadable, and a
     * full or read-only local volume can truncate. This method returning normally is the
     * only thing that entitles a caller to write a database row pointing at the key.
     *
     * Safe to call twice with the same key and bytes: keys are content-addressed, so a
     * repeat write can only replace an object with itself.
     *
     * @param  string  $sha256  The digest the caller computed over `$bytes`.
     *
     * @throws ArtifactStorageException When the write fails, or the read-back does not match.
     */
    public function putVerified(string $disk, ArtifactStorageKey $key, string $bytes, string $sha256): void;

    /**
     * The SHA-256 of a stored object, computed by streaming it back.
     *
     * Never an ETag. An ETag is a transfer checksum whose algorithm depends on how the
     * object was uploaded, and docs/HANDOFF.md section 12 is explicit that it is not this
     * application's document hash.
     *
     * @throws ArtifactStorageException When the object is missing or unreadable.
     */
    public function digestOf(string $disk, string $path): string;

    public function exists(string $disk, string $path): bool;

    /**
     * Read a whole object into memory. For artifacts small enough to hold; downloads stream.
     *
     * @throws ArtifactStorageException When the object is missing or unreadable.
     */
    public function get(string $disk, string $path): string;

    /**
     * A read stream for a download.
     *
     * @return resource
     *
     * @throws ArtifactStorageException When the object is missing or unreadable.
     */
    public function readStream(string $disk, string $path);

    /**
     * Object paths under a prefix, with the epoch second each was last modified.
     *
     * Used by the staging pruner, which needs the age of an object to leave a write that is
     * merely in flight alone.
     *
     * @return array<string, int> Path => last modified, epoch seconds.
     */
    public function listWithTimestamps(string $disk, string $prefix): array;

    /**
     * Remove one object.
     *
     * Only ever called for an object the caller has already proved no artifact row
     * references. Nothing in this module deletes by prefix or by age alone.
     *
     * @throws ArtifactStorageException
     */
    public function delete(string $disk, string $path): void;
}
