<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Documents;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use Throwable;

/**
 * Writes document objects to a private disk and proves they arrived.
 *
 * Two things are deliberate here.
 *
 * **A write is not believed until it is read back.** `putVerified()` writes the object,
 * reads it back through the same storage adapter, and re-hashes it. Only then may a
 * database row point at it. `Filesystem::put()` returning true means the driver accepted
 * the bytes, not that they are retrievable: on S3-compatible stores a write can be accepted
 * and then not be readable, and on a local disk a full or read-only volume can truncate.
 * This is the same "confirm readable bytes and digest through the storage adapter" step the
 * artifact publication process uses (docs/ARCHITECTURE.md).
 *
 * **Nothing here can produce a URL.** There is no `url()`, no `temporaryUrl()`, and no
 * accessor that hands a caller a path to hand to a browser. Downloads stream through a
 * controller that has already run the workspace policy (docs/BLOB_STORAGE.md rule 1). A
 * presigned URL is a bearer token, and the `local` driver cannot mint one at all, so any
 * code that presigned would work in one deployment and 500 in the other.
 */
final readonly class DocumentBlobStore
{
    /** Read back in 512 KB chunks so a large document never has to fit in memory twice. */
    private const CHUNK_BYTES = 524_288;

    public function __construct(private FilesystemManager $filesystems) {}

    public function disk(string $disk): Filesystem
    {
        return $this->filesystems->disk($disk);
    }

    /**
     * Store an object and confirm it reads back with the expected digest.
     *
     * Safe to call twice with the same key and the same bytes: keys are content-addressed,
     * so a repeat write can only replace an object with itself.
     *
     * @param  string  $sha256  The digest the caller computed from the bytes it is storing.
     *
     * @throws DocumentStorageException
     */
    public function putVerified(string $disk, DocumentStorageKey $key, string $bytes, string $sha256): void
    {
        $path = $key->value;

        try {
            $written = $this->disk($disk)->put($path, $bytes);
        } catch (Throwable $exception) {
            throw new DocumentStorageException(
                "Storing document object {$path} on disk [{$disk}] failed: ".$exception->getMessage(),
                previous: $exception,
            );
        }

        if ($written === false) {
            throw new DocumentStorageException("Storing document object {$path} on disk [{$disk}] failed.");
        }

        $readBack = $this->digestOf($disk, $path);

        if (! hash_equals($sha256, $readBack)) {
            throw new DocumentStorageException(
                "Document object {$path} on disk [{$disk}] read back as {$readBack}, expected {$sha256}. "
                .'The stored bytes are not the bytes that were written; no database row was created.',
            );
        }
    }

    /**
     * Remove one document object.
     *
     * The only caller is the retention sweep in `App\Domain\Evidence\Retention`, and it
     * calls this only for an object whose rows it has already deleted in a transaction,
     * having first proved that no envelope and no template version referenced them
     * (docs/operations/retention.md).
     *
     * Two rules from docs/BLOB_STORAGE.md are the caller's, not this method's, and are
     * restated here because this is where somebody looking for a way to delete files will
     * arrive: an object is garbage only by *set membership* against every mapped column,
     * never by the age of its prefix, and a soft-deleted row still counts as a reference. A
     * caller that passes a prefix, or that reasons about the age of an object rather than
     * about the rows naming it, is wrong however this method behaves.
     *
     * Returns false when the object was not there. That is not an error: the row-then-bytes
     * ordering means a re-run of an interrupted sweep will legitimately find some objects
     * already gone.
     *
     * @throws DocumentStorageException When the adapter refused the deletion.
     */
    public function delete(string $disk, string $path): bool
    {
        $filesystem = $this->disk($disk);

        try {
            if (! $filesystem->exists($path)) {
                return false;
            }

            return $filesystem->delete($path);
        } catch (Throwable $exception) {
            throw new DocumentStorageException(
                "Deleting document object {$path} on disk [{$disk}] failed: ".$exception->getMessage(),
                previous: $exception,
            );
        }
    }

    /**
     * The SHA-256 of a stored object, computed by streaming it back.
     *
     * @throws DocumentStorageException When the object is missing or unreadable.
     */
    public function digestOf(string $disk, string $path): string
    {
        try {
            $stream = $this->disk($disk)->readStream($path);
        } catch (Throwable $exception) {
            throw new DocumentStorageException(
                "Reading document object {$path} back from disk [{$disk}] failed: ".$exception->getMessage(),
                previous: $exception,
            );
        }

        if (! is_resource($stream)) {
            throw new DocumentStorageException(
                "Document object {$path} is not readable from disk [{$disk}] immediately after being written.",
            );
        }

        try {
            $context = hash_init('sha256');

            while (! feof($stream)) {
                $chunk = fread($stream, self::CHUNK_BYTES);

                if ($chunk === false) {
                    throw new DocumentStorageException(
                        "Reading document object {$path} from disk [{$disk}] failed part way through.",
                    );
                }

                hash_update($context, $chunk);
            }

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }
}
