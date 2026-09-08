<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Documents;

use App\Domain\Preparation\Contracts\PdfAssembler;
use App\Domain\Preparation\Preflight\PreflightReport;

/**
 * Produces the review revision from an accepted upload.
 *
 * docs/HANDOFF.md section 7: "Any normalization happens before review, is disclosed to the
 * sender, and is traceable to the original." This class is the only place that decides
 * whether anything happens at all, and it records its answer either way.
 *
 * **The default is that nothing happens, and that is a fidelity decision.** The importer
 * behind `PdfAssembler` rebuilds each page as a form XObject. On the engine measured in
 * docs/stage0/pdf-import.md that drops annotations and `/AcroForm` entirely (finding 3),
 * drops `/UserUnit` (finding 2), and emits a fresh creation date, document id, and XMP
 * packet on every run (finding 6). Running it over a document that needs nothing done to
 * it would therefore lose content and produce a review revision that cannot be reproduced
 * from the original. So when no step is required the review revision *is* the original
 * bytes, with the original digest, recorded as `applied: false`.
 *
 * The rebuild path is not dead code kept "for later": it is the path a genuinely required
 * normalization will run on (AcroForm flattening is the expected first one), it is
 * switchable per deployment through `esign.documents.normalization.rebuild_pages`, and it
 * is covered by tests. What it cannot be is silent — turning it on changes both the review
 * digest and the disclosure the sender sees.
 *
 * This is not a "sanitization" pass and is never described as one. Hazardous structures are
 * rejected by preflight before this class runs; nothing here makes an unsafe document safe.
 */
final readonly class ReviewNormalizer
{
    public function __construct(
        private PdfAssembler $assembler,
        private bool $rebuildPages,
    ) {}

    /**
     * @param  PreflightReport  $report  The accepted report for these exact bytes.
     */
    public function normalize(string $originalBytes, string $originalSha256, PreflightReport $report): NormalizedReview
    {
        if (! $this->rebuildPages) {
            return new NormalizedReview(
                $originalBytes,
                $originalSha256,
                $report->metrics->pageCount,
                [
                    'applied' => false,
                    'steps' => [],
                    'summary' => 'None. The review revision is the uploaded file, byte for byte, '
                        .'and carries the same SHA-256 digest as the original.',
                    'disclosures' => [],
                    'source_sha256' => $originalSha256,
                ],
            );
        }

        $assembled = $this->assembler->assemble($originalBytes);
        $bytes = $assembled->bytes;

        return new NormalizedReview(
            $bytes,
            hash('sha256', $bytes),
            count($assembled->outputPages),
            [
                'applied' => true,
                'steps' => ['rebuild_pages'],
                'engine' => 'tc-lib-pdf',
                'summary' => 'Every page was re-imported and the document rewritten, so the review '
                    .'revision has a different SHA-256 from the original. The original is retained '
                    .'unchanged and remains the authoritative record of what was uploaded.',
                'disclosures' => $this->disclosures($report),
                'source_sha256' => $originalSha256,
                'engine_warnings' => array_values($assembled->engineWarnings),
            ],
        );
    }

    /**
     * What the rebuild costs, in the sender's terms.
     *
     * The first three items hold for every rebuild and are stated unconditionally rather
     * than inferred from whether preflight happened to notice them. The rest are the
     * document-specific warnings preflight already raised, repeated here so the sender sees
     * one list rather than having to correlate two.
     *
     * @return array<int, string>
     */
    private function disclosures(PreflightReport $report): array
    {
        $disclosures = [
            'Link, widget and markup annotations are not carried into the review revision; '
                .'only whatever they printed onto the page survives.',
            'Any interactive form (/AcroForm) is not carried into the review revision.',
            'The review revision carries new document identifiers and timestamps, so it is not '
                .'byte-identical to a second rebuild of the same original.',
        ];

        foreach ($report->warnings() as $warning) {
            $disclosures[] = $warning->message;
        }

        return array_values(array_unique($disclosures));
    }
}
