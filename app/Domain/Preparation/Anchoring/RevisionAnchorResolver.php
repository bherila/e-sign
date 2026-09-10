<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Anchoring;

use App\Domain\Preparation\Contracts\PdfPreflight;
use App\Domain\Preparation\Contracts\PdfTextLocator;
use App\Domain\Preparation\Documents\Models\DocumentRevision;
use App\Domain\Preparation\Documents\PreflightPageSizes;
use App\Domain\Preparation\Documents\RevisionBytes;
use App\Domain\Preparation\Preflight\PreflightBudget;
use App\Domain\Preparation\Preflight\PreflightBudgetException;
use App\Domain\Preparation\Preflight\PreflightLimits;
use App\Domain\Preparation\Schema\FieldSchemaDocument;
use App\Domain\Preparation\Schema\PageSizes;
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
 * **The document is only opened when the field set contains an anchor.** A field set with no
 * anchor at all never pays for a parse, which is what makes it safe to run this on every publish
 * and every send rather than only where somebody remembered to. A field set that *does* carry an
 * anchor is resolved every time, receipt or no receipt: see {@see SchemaAnchorResolver} for why a
 * stored receipt is a record of what was found and never permission to skip looking again.
 */
final readonly class RevisionAnchorResolver
{
    public function __construct(
        private PdfTextLocator $text,
        private RevisionBytes $bytes,
        private SchemaAnchorResolver $resolver,
        private PdfPreflight $preflight,
        private PreflightLimits $limits = new PreflightLimits,
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

        if (! $this->hasAnchors($schema)) {
            return AnchorResolutionOutcome::unchanged($schema);
        }

        $bytes = $this->readBytes($revision);

        // One budget for the whole of reading this document: the parse, the content walk, and
        // the per-field matching that follows. Matching is not covered by extraction's ceiling —
        // it starts after extraction returns — and a schema has no field limit, so a document
        // inside every preflight ceiling can still cost runs x fields once the runs are in hand.
        $budget = new PreflightBudget($this->limits);

        try {
            return $this->resolver->resolve(
                $schema,
                $this->runs($schema, $bytes, $revision, $budget),
                $digest,
                $this->pageSizes($revision, $bytes),
                $omitAbsentFields,
                $budget,
            );
        } catch (PreflightBudgetException $exhausted) {
            // A ceiling, crossed anywhere in reading this document: parsing it, walking its
            // content streams, or matching the anchors against the runs that came out. Not a bad
            // field set and not a storage failure — a document this build cannot afford to read,
            // which is what it is from the sender's side too, so it is reported exactly like an
            // unreadable one. The limit that stopped it goes to the log and never to the response.
            $this->log($revision, $exhausted);

            throw new AnchorResolutionFailed($this->unreadableProblems($schema));
        }
    }

    /**
     * The displayed size of every page, which an anchored field cannot be placed without.
     *
     * A resolved rectangle is the matched text's position plus the caller's offset, so whether it
     * lands on the page is only knowable afterwards — and only against a page size. Resolving
     * without one would still place the field, silently skipping the one check that catches an
     * offset which walks off the edge, and a signer would be left with a field they cannot reach.
     *
     * Two sources, in a fixed order. The recorded preflight report first: it is the measurement
     * every stored rectangle was already validated against, so a second one that disagreed would
     * be worse than none. Then a
     * parse of *these* bytes — the ones just proved against the revision's digest — for a row
     * whose report predates page geometry or was written by an older build. There is no third
     * source and no default: a page size is never assumed (AGENTS.md, "Coordinates are never
     * guessed").
     *
     * A document that yields neither is one nothing can be placed on. That is reachable only if
     * the stored object no longer parses at all, which is the same class of fact as bytes that do
     * not hash to their row, so it leaves the same way.
     *
     * @throws AnchorDocumentUnavailable
     */
    private function pageSizes(DocumentRevision $revision, string $bytes): PageSizes
    {
        $recorded = PreflightPageSizes::of($revision->document);

        if ($recorded instanceof PageSizes) {
            return $recorded;
        }

        $sizes = [];

        foreach ($this->preflight->inspect($bytes)->pages as $geometry) {
            $sizes[$geometry->pageNumber] = [
                'width' => $geometry->nativeWidth(),
                'height' => $geometry->nativeHeight(),
            ];
        }

        try {
            return PageSizes::fromMap($sizes);
        } catch (Throwable $unusable) {
            $this->log($revision, $unusable);

            throw AnchorDocumentUnavailable::forRevision((string) $revision->public_id, $unusable);
        }
    }

    private function hasAnchors(FieldSchemaDocument $schema): bool
    {
        foreach ($schema->fields as $field) {
            if ($field->isAnchored()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, TextRun>
     *
     * @throws AnchorResolutionFailed
     * @throws PreflightBudgetException Answered by the caller, with the matching phase's.
     */
    private function runs(
        FieldSchemaDocument $schema,
        string $bytes,
        DocumentRevision $revision,
        PreflightBudget $budget,
    ): array {
        try {
            // Bounded by the same limits the upload was inspected under. Preflight's ceilings
            // describe the *document* — its size, its object count, its streams — and a file can
            // satisfy every one of them while holding millions of small text-showing operators in
            // a single allowed content stream. That is a document nobody can resolve, and without
            // a budget the cost lands on whichever request tried.
            //
            // A ceiling crossed here is deliberately *not* caught here: it is the same fact as one
            // crossed while matching, and `resolve()` answers both in one place. Two handlers that
            // must stay identical are two that can drift.
            return $this->text->extract($bytes, null, $budget);
        } catch (TextExtractionException $failure) {
            // Read, and not parseable. That *is* something about this document, so it is reported
            // to the sender — but only as the stable code. A parser's message carries engine
            // internals, and no document response reveals those (docs/BLOB_STORAGE.md).
            $this->log($revision, $failure);

            throw new AnchorResolutionFailed($this->unreadableProblems($schema));
        }
    }

    /**
     * The revision's stored bytes, proven to be the revision's.
     *
     * A failure here is the storage layer's, not the caller's, so it leaves as
     * {@see AnchorDocumentUnavailable} rather than as something that tells a sender to correct a
     * field set that is perfectly valid. That covers an integrity mismatch too: bytes that are
     * not what the row records are not a document anybody can be asked to sign.
     *
     * @throws AnchorDocumentUnavailable
     */
    private function readBytes(DocumentRevision $revision): string
    {
        try {
            // Verified against the row's digest, not merely fetched. The receipt this resolution
            // writes asserts that digest, so measuring anything in bytes that do not hash to it
            // would put a true-looking label on a false measurement.
            return $this->bytes->read($revision);
        } catch (Throwable $failure) {
            $this->log($revision, $failure);

            throw AnchorDocumentUnavailable::forRevision((string) $revision->public_id, $failure);
        }
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
    private function unreadableProblems(FieldSchemaDocument $schema): array
    {
        $problems = [];

        foreach ($schema->fields as $index => $field) {
            if ($field->anchor === null) {
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
