<?php

declare(strict_types=1);

namespace Tests\Unit\Preparation;

use App\Domain\Preparation\Documents\DocumentStorageException;
use App\Domain\Preparation\Documents\Models\Document;
use App\Domain\Preparation\Documents\Models\DocumentRevision;
use App\Domain\Preparation\Documents\RevisionBytes;
use App\Domain\Preparation\Documents\RevisionKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PdfFixtures;
use Tests\TestCase;

/**
 * Reading a revision proves the object is the revision.
 *
 * `DocumentBlobStore::putVerified()` confirms the bytes when they are written; this is the other
 * half, and it is a different question. Between a write and a read an object can be replaced,
 * truncated, or restored from the wrong backup, and a successful `get()` says nothing about that.
 *
 * It matters because of what is done with the answer: anchor resolution measures text in these
 * bytes and then records the revision's digest as the document the measurement belongs to, and an
 * attestation binds that same digest. Measuring replacement bytes and labelling them with the
 * recorded digest would invite signers against a document the envelope is not bound to, and
 * finalization would only notice afterwards — after signing.
 */
class RevisionBytesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
    }

    public function test_it_returns_the_bytes_when_they_are_the_revisions_own(): void
    {
        $bytes = PdfFixtures::bytes('single-page-letter');
        $revision = $this->revision($bytes, hash('sha256', $bytes));

        $this->assertSame($bytes, app(RevisionBytes::class)->read($revision));
    }

    public function test_it_refuses_bytes_that_are_not_the_revision_the_row_records(): void
    {
        // Written once, replaced afterwards: exactly the case a write-time check cannot see.
        $original = PdfFixtures::bytes('single-page-letter');
        $revision = $this->revision($original, hash('sha256', $original));

        Storage::disk('documents')->put($revision->path, PdfFixtures::bytes('multi-page-mixed-size'));

        $this->expectException(DocumentStorageException::class);
        $this->expectExceptionMessageMatches('/is not the revision it claims to be/');

        app(RevisionBytes::class)->read($revision);
    }

    public function test_it_refuses_an_object_that_is_gone(): void
    {
        $bytes = PdfFixtures::bytes('single-page-letter');
        $revision = $this->revision($bytes, hash('sha256', $bytes));

        Storage::disk('documents')->delete($revision->path);

        $this->expectException(DocumentStorageException::class);

        app(RevisionBytes::class)->read($revision);
    }

    private function revision(string $bytes, string $sha256): DocumentRevision
    {
        $document = Document::factory()->create();
        $path = 'documents/'.$document->public_id.'/review.pdf';

        Storage::disk('documents')->put($path, $bytes);

        return DocumentRevision::query()->create([
            'document_id' => $document->getKey(),
            'kind' => RevisionKind::Review,
            'disk' => 'documents',
            'path' => $path,
            'sha256' => $sha256,
            'bytes' => strlen($bytes),
            'page_count' => 1,
            'normalization' => ['applied' => false],
        ]);
    }
}
