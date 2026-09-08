<?php

declare(strict_types=1);

namespace Tests\Unit\Preparation;

use App\Domain\Preparation\Documents\DocumentBlobStore;
use App\Domain\Preparation\Documents\DocumentStorageException;
use App\Domain\Preparation\Documents\DocumentStorageKey;
use App\Domain\Preparation\Documents\RevisionKind;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * The read-back check, exercised against a disk that misbehaves in each of the ways a real
 * one does: an accepted write that is not readable, and an accepted write that returns
 * different bytes.
 */
class DocumentBlobStoreTest extends TestCase
{
    private const DIGEST = 'bf12cfc222734a3a5507cff6add3e0f752ae8be242880a1b7c1a7165b671426b';

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_a_write_whose_bytes_read_back_differently_is_a_failure(): void
    {
        $store = $this->storeReturning('put', true, readsBack: 'something else entirely');

        $this->expectException(DocumentStorageException::class);
        $this->expectExceptionMessageMatches('/are not the bytes that were written/');

        $store->putVerified('documents', $this->key(), 'the real contract', hash('sha256', 'the real contract'));
    }

    public function test_a_write_that_cannot_be_read_back_at_all_is_a_failure(): void
    {
        $store = $this->storeReturning('put', true, readsBack: null);

        $this->expectException(DocumentStorageException::class);
        $this->expectExceptionMessageMatches('/not readable/');

        $store->putVerified('documents', $this->key(), 'bytes', hash('sha256', 'bytes'));
    }

    public function test_a_driver_that_returns_false_instead_of_throwing_is_a_failure(): void
    {
        $store = $this->storeReturning('put', false, readsBack: 'bytes');

        $this->expectException(DocumentStorageException::class);

        $store->putVerified('documents', $this->key(), 'bytes', hash('sha256', 'bytes'));
    }

    public function test_a_write_that_reads_back_intact_is_accepted(): void
    {
        $store = $this->storeReturning('put', true, readsBack: 'bytes');

        $store->putVerified('documents', $this->key(), 'bytes', hash('sha256', 'bytes'));

        $this->addToAssertionCount(1);
    }

    private function key(): DocumentStorageKey
    {
        return DocumentStorageKey::for(
            '01K4J8Z0000000000000000000',
            '01K4J8Z1111111111111111111',
            RevisionKind::Original,
            self::DIGEST,
        );
    }

    private function storeReturning(string $method, bool $putResult, ?string $readsBack): DocumentBlobStore
    {
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive($method)->andReturn($putResult);

        if ($readsBack === null) {
            $disk->shouldReceive('readStream')->andReturn(null);
        } else {
            $stream = fopen('php://memory', 'r+');
            self::assertIsResource($stream);
            fwrite($stream, $readsBack);
            rewind($stream);
            $disk->shouldReceive('readStream')->andReturn($stream);
        }

        $manager = Mockery::mock(FilesystemManager::class);
        $manager->shouldReceive('disk')->andReturn($disk);

        return new DocumentBlobStore($manager);
    }
}
