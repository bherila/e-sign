<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Finalization\Artifacts;

use App\Domain\Evidence\Finalization\Exceptions\ArtifactStorageException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use Throwable;

/**
 * The {@see ArtifactStore} on a private Laravel disk, with the read-back check that
 * publication depends on.
 *
 * This is the same discipline `App\Domain\Preparation\Documents\DocumentBlobStore` applies at
 * intake, expressed again here because the two modules own different key spaces and neither
 * should be able to write into the other's. The behaviour that matters is
 * {@see putVerified()}: write, then read back through the same adapter and re-hash. Only when
 * that agrees may an `artifacts` row exist.
 */
final readonly class DiskArtifactStore implements ArtifactStore
{
    /** Read back in 512 KB chunks so a large artifact never has to fit in memory twice. */
    private const CHUNK_BYTES = 524_288;

    public function __construct(private FilesystemManager $filesystems) {}

    public function putVerified(string $disk, ArtifactStorageKey $key, string $bytes, string $sha256): void
    {
        $path = $key->value;

        try {
            $written = $this->disk($disk)->put($path, $bytes);
        } catch (Throwable $exception) {
            throw new ArtifactStorageException(
                "Storing artifact {$path} on disk [{$disk}] failed: ".$exception->getMessage(),
                previous: $exception,
            );
        }

        if ($written === false) {
            throw new ArtifactStorageException("Storing artifact {$path} on disk [{$disk}] failed.");
        }

        $readBack = $this->digestOf($disk, $path);

        if (! hash_equals($sha256, $readBack)) {
            throw new ArtifactStorageException(
                "Artifact {$path} on disk [{$disk}] read back as {$readBack}, expected {$sha256}. "
                .'The stored bytes are not the bytes that were written; nothing is published.',
            );
        }
    }

    public function digestOf(string $disk, string $path): string
    {
        $stream = $this->readStream($disk, $path);

        try {
            $context = hash_init('sha256');

            while (! feof($stream)) {
                $chunk = fread($stream, self::CHUNK_BYTES);

                if ($chunk === false) {
                    throw new ArtifactStorageException(
                        "Reading artifact {$path} from disk [{$disk}] failed part way through.",
                    );
                }

                hash_update($context, $chunk);
            }

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }

    public function exists(string $disk, string $path): bool
    {
        try {
            return $this->disk($disk)->exists($path);
        } catch (Throwable $exception) {
            throw new ArtifactStorageException(
                "Checking artifact {$path} on disk [{$disk}] failed: ".$exception->getMessage(),
                previous: $exception,
            );
        }
    }

    public function get(string $disk, string $path): string
    {
        $stream = $this->readStream($disk, $path);

        try {
            $contents = stream_get_contents($stream);
        } finally {
            fclose($stream);
        }

        if ($contents === false) {
            throw new ArtifactStorageException("Artifact {$path} on disk [{$disk}] could not be read.");
        }

        return $contents;
    }

    /**
     * @return resource
     */
    public function readStream(string $disk, string $path)
    {
        try {
            $stream = $this->disk($disk)->readStream($path);
        } catch (Throwable $exception) {
            throw new ArtifactStorageException(
                "Reading artifact {$path} from disk [{$disk}] failed: ".$exception->getMessage(),
                previous: $exception,
            );
        }

        if (! is_resource($stream)) {
            throw new ArtifactStorageException("Artifact {$path} is not readable from disk [{$disk}].");
        }

        return $stream;
    }

    public function listWithTimestamps(string $disk, string $prefix): array
    {
        $filesystem = $this->disk($disk);
        $found = [];

        try {
            foreach ($filesystem->allFiles($prefix) as $path) {
                $found[$path] = (int) $filesystem->lastModified($path);
            }
        } catch (Throwable $exception) {
            throw new ArtifactStorageException(
                "Listing artifacts under {$prefix} on disk [{$disk}] failed: ".$exception->getMessage(),
                previous: $exception,
            );
        }

        return $found;
    }

    public function delete(string $disk, string $path): void
    {
        try {
            $this->disk($disk)->delete($path);
        } catch (Throwable $exception) {
            throw new ArtifactStorageException(
                "Deleting {$path} on disk [{$disk}] failed: ".$exception->getMessage(),
                previous: $exception,
            );
        }
    }

    private function disk(string $disk): Filesystem
    {
        return $this->filesystems->disk($disk);
    }
}
