<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Anchoring;

use App\Domain\Preparation\Contracts\PdfTextLocator;
use App\Domain\Preparation\Documents\DocumentBlobStore;
use App\Domain\Preparation\Documents\DocumentStorageException;
use App\Domain\Preparation\Documents\Models\DocumentRevision;
use App\Domain\Preparation\Documents\PreflightPageSizes;
use App\Domain\Preparation\Schema\FieldSchemaDocument;
use App\Domain\Preparation\Schema\ValidationCode;
use App\Domain\Preparation\Text\TextExtractionException;
use App\Domain\Preparation\Text\TextRun;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Anchor resolution against the bytes of one immutable document revision.
 *
 * The pairing of a field document with the PDF it is placed on. It reads the revision's stored
 * object, extracts positioned text with {@see PdfTextLocator} — a real content-stream parse,
 * never a regular expression over compressed bytes — and hands both to
 * {@see SchemaAnchorResolver}.
 *
 * Two things are deliberate.
 *
 * **The digest comes from the revision row, not from the bytes.** A revision is immutable and its
 * `sha256` is what every attestation binds to, so that is the identity the resolution receipt
 * records. Hashing the bytes again here would produce a second answer to a question that already
 * has one.
 *
 * **The document is only opened when there is an anchor to resolve.** A field set with no
 * unresolved anchor never pays for a parse, which is what makes it safe to run this on every
 * publish and every send rather than only where somebody remembered to.
 */
final readonly class RevisionAnchorResolver
{
    public function __construct(
        private PdfTextLocator $text,
        private DocumentBlobStore $blobs,
        private SchemaAnchorResolver $resolver,
    ) {}

    /**
     * @throws AnchorResolutionFailed
     */
    public function resolve(
        DocumentRevision $revision,
        FieldSchemaDocument $schema,
        bool $omitAbsentFields = true,
    ): AnchorResolutionOutcome {
        $digest = (string) $revision->sha256;

        if (! $this->hasWorkToDo($schema, $digest)) {
            return AnchorResolutionOutcome::unchanged($schema);
        }

        return $this->resolver->resolve(
            $schema,
            $this->runs($schema, $digest, $revision),
            $digest,
            PreflightPageSizes::of($revision->document),
            $omitAbsentFields,
        );
    }

    private function hasWorkToDo(FieldSchemaDocument $schema, string $documentSha256): bool
    {
        foreach ($schema->fields as $field) {
            if ($field->anchorNeedsResolution($documentSha256)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, TextRun>
     *
     * @throws AnchorResolutionFailed
     */
    private function runs(FieldSchemaDocument $schema, string $documentSha256, DocumentRevision $revision): array
    {
        $bytes = $this->bytes($revision);

        try {
            return $this->text->extract($bytes);
        } catch (TextExtractionException $failure) {
            // Read, and not parseable. That *is* something about this document, so it is reported
            // to the sender — but only as the stable code. A parser's message carries engine
            // internals, and no document response reveals those (docs/BLOB_STORAGE.md).
            $this->log($revision, $failure);

            throw new AnchorResolutionFailed($this->unreadableProblems($schema, $documentSha256));
        }
    }

    /**
     * The revision's stored bytes.
     *
     * A failure here is the storage layer's, not the caller's, so it leaves as
     * {@see AnchorDocumentUnavailable} rather than as something that tells a sender to correct a
     * field set that is perfectly valid.
     *
     * @throws AnchorDocumentUnavailable
     */
    private function bytes(DocumentRevision $revision): string
    {
        try {
            $bytes = $this->blobs->disk((string) $revision->disk)->get((string) $revision->path);
        } catch (Throwable $failure) {
            $this->log($revision, $failure);

            throw AnchorDocumentUnavailable::forRevision((string) $revision->public_id, $failure);
        }

        if (! is_string($bytes) || $bytes === '') {
            $this->log($revision, new DocumentStorageException(
                'Document revision '.$revision->public_id.' has no readable bytes on disk ['.$revision->disk.'].',
            ));

            throw AnchorDocumentUnavailable::forRevision((string) $revision->public_id);
        }

        return $bytes;
    }

    /**
     * The whole cause, in the log, where an operator can see it.
     *
     * A filesystem adapter's message routinely carries the private disk name and object path, and
     * a parser's carries engine internals. Neither reaches a response.
     */
    private function log(DocumentRevision $revision, Throwable $failure): void
    {
        Log::error('Anchor resolution could not read a document revision.', [
            'document_revision_id' => $revision->public_id,
            'exception' => $failure::class,
            'message' => $failure->getMessage(),
        ]);
    }

    /**
     * Extraction failed, so *every* unresolved anchor in the document failed with it.
     *
     * Reporting one problem per affected field rather than a single document-level error keeps
     * the two surfaces honest: the editor still annotates each field it cannot place, and a
     * caller reading `details.problems[]` sees the same list it would see for any other anchor
     * failure. The cause is identical in each message, because it is — and it is deliberately
     * *not* in them: see where this is called for why the detail is logged rather than returned.
     *
     * @return list<AnchorResolutionProblem>
     */
    private function unreadableProblems(FieldSchemaDocument $schema, string $documentSha256): array
    {
        $problems = [];

        foreach ($schema->fields as $index => $field) {
            if (! $field->anchorNeedsResolution($documentSha256) || $field->anchor === null) {
                continue;
            }

            $problems[] = new AnchorResolutionProblem(
                $index,
                $field->id,
                $field->recipientId,
                $field->anchor->text,
                ValidationCode::AnchorTextUnreadable,
                'the document\'s text could not be read',
                'Field "'.$field->id.'" is anchored to "'.$field->anchor->text.'", and the document\'s text could '
                    .'not be read, so no anchor in it can be resolved. The cause is in the service log.',
            );
        }

        return $problems;
    }
}
