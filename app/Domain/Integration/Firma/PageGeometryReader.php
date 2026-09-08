<?php

declare(strict_types=1);

namespace App\Domain\Integration\Firma;

use App\Domain\Preparation\Contracts\PdfPreflight;
use App\Domain\Preparation\Documents\DocumentBlobStore;
use App\Domain\Preparation\Documents\Models\DocumentRevision;
use App\Domain\Preparation\Geometry\PageBox;
use App\Domain\Preparation\Geometry\PageGeometry;
use App\Domain\Preparation\Geometry\PageRotation;
use App\Domain\Signing\Models\Envelope;

/**
 * The displayed size of every page of the document an envelope was built from.
 *
 * The facade needs this on every request that mentions a coordinate, in both directions: a
 * percentage arriving on `create-and-send` becomes points by multiplying by the page, and a
 * stored rectangle becomes a percentage on `/fields` by dividing by it. Percentages are
 * relative to the **displayed** page, so a `/Rotate 90` Letter page treats 50% of `x` as
 * 396 pt and not 306 pt (`docs/preparation/coordinate-space.md`).
 *
 * ## Two sources, in this order
 *
 * 1. **The recorded preflight report.** `documents.preflight_report` carries every page's
 *    MediaBox, CropBox, rotation and `/UserUnit` from the parse that accepted the upload.
 *    That is the measurement the field rectangles were validated against, so it is the one
 *    a conversion must agree with.
 * 2. **A fresh parse of the reviewed revision.** Only when the report holds no geometry —
 *    a document ingested before the report recorded pages, or one whose report was written
 *    by an older build. Re-measuring costs a parse; the alternative is refusing to answer a
 *    read, or worse, assuming a page size.
 *
 * There is deliberately no third source and no default. "Assume Letter" would misplace every
 * field on an A4 document by four percent of the page, and nothing here infers a page size
 * from the coordinates it is asked to convert (AGENTS.md, "Coordinates are never guessed").
 * A page the document does not have is an error the caller reads.
 *
 * Results are memoised per revision for the life of the instance, which is one request: a
 * twelve-field response then parses the document at most once.
 */
final class PageGeometryReader
{
    /** @var array<int, array<int, PageGeometry>> Revision key => page number => geometry. */
    private array $byRevision = [];

    public function __construct(
        private readonly PdfPreflight $preflight,
        private readonly DocumentBlobStore $blobs,
    ) {}

    /**
     * @return array<int, PageGeometry> Keyed by 1-based page number.
     */
    public function forRevision(DocumentRevision $revision): array
    {
        $key = (int) $revision->getKey();

        if (isset($this->byRevision[$key])) {
            return $this->byRevision[$key];
        }

        $pages = $this->fromReport($revision);

        if ($pages === []) {
            $pages = $this->fromBytes($revision);
        }

        return $this->byRevision[$key] = $pages;
    }

    /**
     * The geometry of the document an envelope was built from.
     *
     * `loadMissing` rather than a query, so a response that mentions twelve fields resolves
     * the revision once and re-reads Eloquent's cached relation eleven times.
     *
     * @return array<int, PageGeometry>
     *
     * @throws FirmaException When the revision is gone.
     */
    public function forEnvelope(Envelope $envelope): array
    {
        return $this->forRevision($this->revisionOf($envelope));
    }

    /**
     * @throws FirmaException When the document has no such page.
     */
    public function pageOf(Envelope $envelope, int $pageNumber): PageGeometry
    {
        return $this->page($this->revisionOf($envelope), $pageNumber);
    }

    /**
     * @throws FirmaException When the revision is gone.
     */
    public function revisionOf(Envelope $envelope): DocumentRevision
    {
        $envelope->loadMissing('documentRevision.document');
        $revision = $envelope->documentRevision;

        if (! $revision instanceof DocumentRevision) {
            throw FirmaException::of(
                FirmaErrorCode::InternalError,
                'The document this signing request was built from is no longer available, so nothing about '
                .'its pages can be reported.',
            );
        }

        return $revision;
    }

    /**
     * @throws FirmaException When the document has no such page.
     */
    public function page(DocumentRevision $revision, int $pageNumber): PageGeometry
    {
        $pages = $this->forRevision($revision);

        if (! isset($pages[$pageNumber])) {
            throw FirmaException::of(
                FirmaErrorCode::InvalidRequest,
                'The document has no page '.$pageNumber.'. It has '.count($pages).'.',
                ['page_number' => $pageNumber, 'page_count' => count($pages)],
            );
        }

        return $pages[$pageNumber];
    }

    /**
     * @return array<int, PageGeometry>
     */
    private function fromReport(DocumentRevision $revision): array
    {
        $document = $revision->relationLoaded('document') ? $revision->document : $revision->document()->first();
        $report = is_array($document?->preflight_report) ? $document->preflight_report : [];
        $rows = is_array($report['pages'] ?? null) ? $report['pages'] : [];
        $pages = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! is_array($row['media_box'] ?? null)) {
                continue;
            }

            $number = (int) ($row['page'] ?? 0);

            if ($number < 1) {
                continue;
            }

            $pages[$number] = PageGeometry::create(
                $number,
                PageBox::fromArray($row['media_box']),
                is_array($row['crop_box'] ?? null) ? PageBox::fromArray($row['crop_box']) : null,
                PageRotation::fromDegrees((int) ($row['rotation'] ?? 0)),
                (float) ($row['user_unit'] ?? 1.0),
            );
        }

        return $pages;
    }

    /**
     * @return array<int, PageGeometry>
     *
     * @throws FirmaException When the bytes are gone.
     */
    private function fromBytes(DocumentRevision $revision): array
    {
        $bytes = $this->blobs->disk($revision->disk)->get($revision->path);

        if (! is_string($bytes)) {
            throw FirmaException::of(
                FirmaErrorCode::InternalError,
                'The document behind this signing request could not be read, so its page sizes are '
                .'unknown and no coordinate can be converted.',
            );
        }

        $pages = [];

        foreach ($this->preflight->inspect($bytes)->pages as $geometry) {
            $pages[$geometry->pageNumber] = $geometry;
        }

        return $pages;
    }
}
