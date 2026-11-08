<?php

declare(strict_types=1);

namespace App\Domain\Integration\Firma;

use App\Domain\Preparation\Documents\Models\Document;
use App\Domain\Preparation\Preflight\PreflightCode;

/**
 * The answer when intake kept a document but the preflight parser refused it.
 *
 * Shared by every route that takes a PDF — inline on create, and both uploads (issue #182) — so a
 * damaged page is the same `malformed_pdf` with the same `malformed_pages` wherever it arrives.
 * Upstream names the document by `document_position` (1-based, request order) and, for an upload,
 * by its `document_id`.
 */
final class PreflightRefusal
{
    /**
     * The preflight parser refused the document. Its findings go to the caller verbatim.
     *
     * The document row is deliberately kept, with its report — that is intake's rule, and it
     * is what an operator needs when a sender says "the system rejected our contract". No
     * envelope exists, so nothing is half-built.
     */
    public static function of(Document $document, int $position = 1, ?string $uploadId = null): FirmaException
    {
        $report = is_array($document->preflight_report) ? $document->preflight_report : [];
        $findings = is_array($report['findings'] ?? null) ? $report['findings'] : [];

        // A damaged page is upstream's own refusal (`MALFORMED_PDF`, reference v1.40.1), so it
        // gets its own token and the page numbers a caller shows its user. The findings ride
        // along unchanged, so nothing else the parser found is hidden behind it.
        $malformedPages = [];
        foreach ($findings as $finding) {
            if (is_array($finding) && ($finding['code'] ?? null) === PreflightCode::MalformedPage->value
                && is_int($finding['page'] ?? null)) {
                $malformedPages[] = $finding['page'];
            }
        }

        if ($malformedPages !== []) {
            sort($malformedPages);

            return FirmaException::of(
                FirmaErrorCode::MalformedPdf,
                'The PDF is damaged: page '.$malformedPages[0].' has no valid content. Repair or '
                .'re-export the file, then upload it again.',
                [
                    'malformed_pages' => array_values(array_unique($malformedPages)),
                    'document_position' => $position,
                    ...($uploadId === null ? [] : ['document_id' => $uploadId]),
                    'findings' => array_values($findings),
                ],
            );
        }

        return FirmaException::of(
            FirmaErrorCode::InvalidRequest,
            'The document was refused by the PDF preflight check, so it cannot be used.',
            [
                'findings' => array_values($findings),
                ...($uploadId === null ? [] : ['document_position' => $position, 'document_id' => $uploadId]),
            ],
        );
    }
}
