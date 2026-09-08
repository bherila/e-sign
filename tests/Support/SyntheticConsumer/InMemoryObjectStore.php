<?php

declare(strict_types=1);

namespace Tests\Support\SyntheticConsumer;

use Illuminate\Filesystem\FilesystemAdapter as LaravelFilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\Visibility;

/**
 * A flat, in-memory object store, shaped like an S3-compatible bucket rather than a disk.
 *
 * Release gate 12 asks for proof that Garage — or any S3-compatible backend — is swappable.
 * Running the suite a second time on `local` with a different root proves almost nothing:
 * both runs are the same adapter, so anything that quietly depends on a real filesystem
 * survives both and breaks on the first deployment. This adapter is the useful second run,
 * because the ways it differs are the ways an object store differs:
 *
 * - **There are no directories.** A key is a key. `createDirectory()` is a no-op and
 *   `directoryExists()` answers from key prefixes, exactly as S3 does. Code that expected
 *   `mkdir` semantics fails here.
 * - **Listing is a prefix scan**, not a directory walk, so `allFiles()` returning the right
 *   set is a property of the keys and not of a tree.
 * - **Deleting an object leaves no empty parent behind**, because there was never a parent.
 *
 * What it is *not* is a claim about Garage. It speaks Flysystem, not S3-over-HTTP, so it says
 * nothing about signatures, multipart uploads, or eventual consistency. What it establishes is
 * the thing this repository can establish without a container runtime: no code in the
 * publication path branches on the driver or needs the local one. `tests/EndToEnd/README.md`
 * states that boundary.
 *
 * Objects live in a static registry keyed by bucket, so `Storage::forgetDisk()` and a
 * re-resolution of the same disk see the same bytes — which is what a real bucket does and
 * what {@see SyntheticConsumer::useDocumentsDisk()} depends on.
 */
final class InMemoryObjectStore implements FilesystemAdapter
{
    /** The driver name to put in a disk's configuration. */
    public const DRIVER = 'esign-in-memory-object-store';

    /**
     * @var array<string, array<string, array{bytes: string, at: int, visibility: string}>>
     */
    private static array $buckets = [];

    public function __construct(private readonly string $bucket) {}

    /**
     * Teach the filesystem manager about this driver.
     *
     * Idempotent, so a test can call it without knowing whether another already did.
     *
     * The class is named in full rather than as `self`: `FilesystemManager::extend()` rebinds
     * the callback's scope to itself, so `new self` inside it would construct a filesystem
     * manager. Worth the noise, because the failure is a type error a long way from here.
     */
    public static function register(): void
    {
        Storage::extend(self::DRIVER, function (mixed $app, array $config): LaravelFilesystemAdapter {
            $adapter = new InMemoryObjectStore((string) ($config['bucket'] ?? 'default'));

            return new LaravelFilesystemAdapter(
                new Filesystem($adapter, $config),
                $adapter,
                $config,
            );
        });
    }

    /**
     * The disk configuration for one bucket.
     *
     * @return array<string, mixed>
     */
    public static function disk(string $bucket): array
    {
        return [
            'driver' => self::DRIVER,
            'bucket' => $bucket,
            'visibility' => 'private',
            // Same as the production `documents` disk: a storage failure is an exception, not
            // a falsy return somebody has to remember to check.
            'throw' => true,
            'report' => false,
        ];
    }

    public static function forget(): void
    {
        self::$buckets = [];
    }

    /** Every key in a bucket, for a test that wants to look behind the adapter. */
    public static function keys(string $bucket): array
    {
        $keys = array_keys(self::$buckets[$bucket] ?? []);
        sort($keys);

        return $keys;
    }

    /* ------------------------------------------------------------ FilesystemAdapter */

    public function fileExists(string $path): bool
    {
        return isset(self::$buckets[$this->bucket][$path]);
    }

    public function directoryExists(string $path): bool
    {
        $prefix = rtrim($path, '/').'/';

        foreach (array_keys(self::$buckets[$this->bucket] ?? []) as $key) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function write(string $path, string $contents, Config $config): void
    {
        self::$buckets[$this->bucket][$path] = [
            'bytes' => $contents,
            'at' => time(),
            'visibility' => (string) $config->get('visibility', Visibility::PRIVATE),
        ];
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $bytes = stream_get_contents($contents);

        $this->write($path, $bytes === false ? '' : $bytes, $config);
    }

    public function read(string $path): string
    {
        if (! $this->fileExists($path)) {
            throw UnableToReadFile::fromLocation($path, 'No such object in bucket '.$this->bucket.'.');
        }

        return self::$buckets[$this->bucket][$path]['bytes'];
    }

    public function readStream(string $path)
    {
        $stream = fopen('php://temp', 'r+b');

        if ($stream === false) {
            throw UnableToReadFile::fromLocation($path, 'Could not open a stream.');
        }

        fwrite($stream, $this->read($path));
        rewind($stream);

        return $stream;
    }

    public function delete(string $path): void
    {
        unset(self::$buckets[$this->bucket][$path]);
    }

    public function deleteDirectory(string $path): void
    {
        $prefix = rtrim($path, '/').'/';

        foreach (array_keys(self::$buckets[$this->bucket] ?? []) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset(self::$buckets[$this->bucket][$key]);
            }
        }
    }

    /** A bucket has no directories to create. */
    public function createDirectory(string $path, Config $config): void {}

    public function setVisibility(string $path, string $visibility): void
    {
        if ($this->fileExists($path)) {
            self::$buckets[$this->bucket][$path]['visibility'] = $visibility;
        }
    }

    public function visibility(string $path): FileAttributes
    {
        return new FileAttributes($path, visibility: self::$buckets[$this->bucket][$path]['visibility'] ?? Visibility::PRIVATE);
    }

    public function mimeType(string $path): FileAttributes
    {
        // Deliberately unknown. Nothing in the publication path may take a content type from
        // storage: it is fixed per artifact kind (docs/HANDOFF.md section 12), and an adapter
        // that guessed one would hide a build that had started trusting it.
        return new FileAttributes($path, mimeType: null);
    }

    public function lastModified(string $path): FileAttributes
    {
        return new FileAttributes($path, lastModified: self::$buckets[$this->bucket][$path]['at'] ?? time());
    }

    public function fileSize(string $path): FileAttributes
    {
        return new FileAttributes($path, fileSize: strlen($this->read($path)));
    }

    public function listContents(string $path, bool $deep): iterable
    {
        $prefix = $path === '' ? '' : rtrim($path, '/').'/';
        $directories = [];

        foreach (self::$buckets[$this->bucket] ?? [] as $key => $object) {
            if ($prefix !== '' && ! str_starts_with($key, $prefix)) {
                continue;
            }

            $remainder = substr($key, strlen($prefix));

            if (! $deep && str_contains($remainder, '/')) {
                // A shallow listing reports the synthetic prefix, the way S3 reports a common
                // prefix, and never the object beneath it.
                $directories[$prefix.strstr($remainder, '/', true)] = true;

                continue;
            }

            yield new FileAttributes(
                $key,
                fileSize: strlen($object['bytes']),
                visibility: $object['visibility'],
                lastModified: $object['at'],
            );
        }

        foreach (array_keys($directories) as $directory) {
            yield new DirectoryAttributes($directory);
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $this->copy($source, $destination, $config);
        $this->delete($source);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        self::$buckets[$this->bucket][$destination] = self::$buckets[$this->bucket][$source]
            ?? throw UnableToReadFile::fromLocation($source, 'No such object in bucket '.$this->bucket.'.');
    }
}
