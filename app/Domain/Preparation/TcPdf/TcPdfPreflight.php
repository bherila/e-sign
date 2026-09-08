<?php

declare(strict_types=1);

namespace App\Domain\Preparation\TcPdf;

use App\Domain\Preparation\Contracts\PdfPreflight;
use App\Domain\Preparation\Geometry\PageGeometry;
use App\Domain\Preparation\Preflight\DocumentMetrics;
use App\Domain\Preparation\Preflight\PreflightBudget;
use App\Domain\Preparation\Preflight\PreflightBudgetException;
use App\Domain\Preparation\Preflight\PreflightCode;
use App\Domain\Preparation\Preflight\PreflightFinding;
use App\Domain\Preparation\Preflight\PreflightLimits;
use App\Domain\Preparation\Preflight\PreflightReport;
use App\Domain\Preparation\Preflight\PreflightSeverity;
use App\Domain\Preparation\TcPdf\Parsing\BoundedPdfParser;
use App\Domain\Preparation\TcPdf\Parsing\MalformedPageTreeException;
use App\Domain\Preparation\TcPdf\Parsing\PageTreeReader;
use App\Domain\Preparation\TcPdf\Parsing\PdfObjectGraph;

/**
 * Preflight built on tc-lib-pdf-parser.
 *
 * The classification policy is deliberately conservative and is stated in
 * docs/stage0/pdf-import.md:
 *
 *  - encrypted, already-signed, JavaScript, XFA, embedded-file and /Launch documents
 *    are rejected, because the importer reconstitutes pages and would silently drop or
 *    invalidate exactly those structures;
 *  - anything that cannot be parsed is rejected rather than passed through;
 *  - features that survive import but lose fidelity produce warnings, so the sender can
 *    be told before review rather than after signing.
 *
 * Detection walks every parsed object, including objects inside object streams, so a
 * hazard hidden behind an indirect reference or a compressed object is still found.
 *
 * Resource ceilings are charged *while* the document is read, not after it: see
 * {@see PreflightBudget} for what is counted and {@see BoundedPdfParser}
 * for where. A budget that trips aborts the parse and becomes a rejection naming the
 * ceiling, never a generic `unparseable`.
 */
final readonly class TcPdfPreflight implements PdfPreflight
{
    /**
     * @param  (\Closure(): float)|null  $clock  Seconds, for the wall-clock backstop. Injected so a
     *                                           test can make the time budget trip without sleeping
     *                                           for the length of it.
     */
    public function __construct(
        private PreflightLimits $limits = new PreflightLimits,
        private ?\Closure $clock = null,
    ) {}

    public function inspect(string $pdfBytes): PreflightReport
    {
        $budget = new PreflightBudget($this->limits, $this->clock);

        $findings = [];
        $pages = [];
        $objectCount = 0;

        if (strlen($pdfBytes) > $this->limits->maxBytes) {
            return $this->report(
                [PreflightFinding::reject(
                    PreflightCode::SizeLimitExceeded,
                    sprintf(
                        'The file is %d bytes; the limit is %d bytes. Split the document or reduce '
                        .'embedded image resolution before uploading.',
                        strlen($pdfBytes),
                        $this->limits->maxBytes,
                    ),
                )],
                [],
                $pdfBytes,
                0,
                $budget,
            );
        }

        try {
            $graph = PdfObjectGraph::parse($pdfBytes, $budget);
        } catch (PreflightBudgetException $exception) {
            return $this->report(
                [PreflightFinding::reject($exception->preflightCode, $exception->getMessage())],
                [],
                $pdfBytes,
                $budget->objectCount(),
                $budget,
            );
        } catch (\Throwable $exception) {
            return $this->report(
                [PreflightFinding::reject(
                    PreflightCode::Unparseable,
                    'The file could not be parsed as a PDF: '.$exception->getMessage()
                    .' Re-export the document from the application that produced it and upload it again.',
                )],
                [],
                $pdfBytes,
                0,
                $budget,
            );
        }

        $objectCount = $graph->objectCount();

        if ($graph->isEncrypted()) {
            // The parser cannot decrypt, so nothing below this point would be reliable.
            return $this->report(
                [PreflightFinding::reject(
                    PreflightCode::Encrypted,
                    'This PDF is encrypted (it has a /Encrypt dictionary). Encrypted documents cannot be '
                    .'prepared for signing. Remove the password or permissions protection and upload the '
                    .'unprotected file.',
                )],
                [],
                $pdfBytes,
                $objectCount,
                $budget,
            );
        }

        try {
            // Objects reached through an /ObjStm are stored by the parser without passing
            // through the seam the budget charges, so the total is re-checked here. The
            // budget owns the message either way, so the same ceiling reads the same way
            // whichever path found it.
            if ($this->limits->maxObjects > 0 && $objectCount > $this->limits->maxObjects) {
                $budget->exhaustObjects();
            }

            foreach ($this->scanHazards($graph, $budget) as $finding) {
                $findings[] = $finding;
            }

            $flattened = (new PageTreeReader($graph))->pages($this->limits->maxPages);
            foreach ($flattened as $page) {
                $budget->tick();
                $pages[] = $page->geometry;

                if ($page->geometry->userUnit !== 1.0) {
                    $findings[] = PreflightFinding::warn(
                        PreflightCode::UserUnitNotPreserved,
                        sprintf(
                            'Page %d declares /UserUnit %.4F. The import engine does not carry /UserUnit into '
                            .'the assembled document, so the output page will be the same number of points but '
                            .'a different physical size.',
                            $page->geometry->pageNumber,
                            $page->geometry->userUnit,
                        ),
                        $page->geometry->pageNumber,
                    );
                }

                if ($page->geometry->cropBox->x0 !== 0.0 || $page->geometry->cropBox->y0 !== 0.0) {
                    $findings[] = PreflightFinding::warn(
                        PreflightCode::NonZeroCropBoxOrigin,
                        sprintf(
                            'Page %d has a CropBox whose origin is (%.4F, %.4F). The import engine positions '
                            .'imported content from the box origin rather than the page corner, so assembly '
                            .'compensates for the offset explicitly.',
                            $page->geometry->pageNumber,
                            $page->geometry->cropBox->x0,
                            $page->geometry->cropBox->y0,
                        ),
                        $page->geometry->pageNumber,
                    );
                }

                if ($page->annotations !== []) {
                    $findings[] = PreflightFinding::warn(
                        PreflightCode::AnnotationsNotPreserved,
                        sprintf(
                            'Page %d carries %d annotation(s). Pages are imported as form XObjects, so link, '
                            .'widget and markup annotations are not carried into the assembled document; only '
                            .'their printed appearance is.',
                            $page->geometry->pageNumber,
                            count($page->annotations),
                        ),
                        $page->geometry->pageNumber,
                    );
                }
            }
        } catch (MalformedPageTreeException $exception) {
            $findings[] = PreflightFinding::reject(
                PreflightCode::InvalidPageGeometry,
                'The page tree could not be read: '.$exception->getMessage(),
            );
        } catch (PreflightBudgetException $exception) {
            return $this->report(
                [PreflightFinding::reject($exception->preflightCode, $exception->getMessage())],
                [],
                $pdfBytes,
                $objectCount,
                $budget,
            );
        }

        if ($pages === [] && ! $this->hasRejection($findings)) {
            $findings[] = PreflightFinding::reject(
                PreflightCode::NoPages,
                'The document does not contain any pages.',
            );
        }

        return $this->report($findings, $pages, $pdfBytes, $objectCount, $budget);
    }

    /**
     * @return array<int, PreflightFinding>
     *
     * @throws PreflightBudgetException
     */
    private function scanHazards(PdfObjectGraph $graph, PreflightBudget $budget): array
    {
        $codes = [];

        foreach ($graph->objectRefs() as $ref) {
            $budget->tick();

            foreach ($graph->object($ref) as $entry) {
                $this->scanEntry($graph, $entry, $codes, 0);
            }
        }

        $messages = [
            PreflightCode::AlreadySigned->value => 'This PDF already carries a digital signature. Importing it '
                .'would rebuild its pages and invalidate that signature, so it cannot be prepared for signing. '
                .'Upload the unsigned original, or route the executed file to document retention instead.',
            PreflightCode::Xfa->value => 'This PDF uses an XFA (XML Forms Architecture) form. XFA forms are not '
                .'supported: their fields would be lost. Flatten the form to a static PDF and upload that.',
            PreflightCode::JavaScript->value => 'This PDF contains JavaScript. Scripted documents are not accepted '
                .'because the script would not run in the signing view and its effect on the visible content '
                .'cannot be reproduced. Remove the scripting and upload a static PDF.',
            PreflightCode::EmbeddedFile->value => 'This PDF has one or more embedded file attachments. Attachments '
                .'are not carried into the signed document and would silently disappear. Remove them, or send '
                .'them as separate documents.',
            PreflightCode::LaunchAction->value => 'This PDF contains a /Launch action, which asks a reader to run '
                .'an external program. Documents with launch actions are not accepted.',
        ];

        $findings = [];
        foreach ($messages as $code => $message) {
            if (isset($codes[$code])) {
                $findings[] = PreflightFinding::reject(PreflightCode::from($code), $message);
            }
        }

        return $findings;
    }

    /**
     * @param  array<int, mixed>  $entry
     * @param  array<string, bool>  $codes
     */
    private function scanEntry(PdfObjectGraph $graph, array $entry, array &$codes, int $depth): void
    {
        if ($depth > 32) {
            return;
        }

        $type = $entry[0] ?? null;

        if ($type === '<<') {
            $dict = $graph->decodeDictionary($entry);
            $this->classifyDictionary($graph, $dict, $codes);

            foreach ($dict as $value) {
                $this->scanEntry($graph, $value, $codes, $depth + 1);
            }

            return;
        }

        if ($type === '[' && is_array($entry[1] ?? null)) {
            foreach ($entry[1] as $item) {
                if (is_array($item)) {
                    $this->scanEntry($graph, $item, $codes, $depth + 1);
                }
            }
        }
    }

    /**
     * @param  array<string, array<int, mixed>>  $dict
     * @param  array<string, bool>  $codes
     */
    private function classifyDictionary(PdfObjectGraph $graph, array $dict, array &$codes): void
    {
        if (array_key_exists('XFA', $dict)) {
            $codes[PreflightCode::Xfa->value] = true;
        }

        if (array_key_exists('JS', $dict) || array_key_exists('JavaScript', $dict)) {
            $codes[PreflightCode::JavaScript->value] = true;
        }

        if (array_key_exists('EmbeddedFiles', $dict) || array_key_exists('EF', $dict)) {
            $codes[PreflightCode::EmbeddedFile->value] = true;
        }

        $subtype = $graph->dictEntryAsName($dict, 'S');
        if ($subtype === 'JavaScript') {
            $codes[PreflightCode::JavaScript->value] = true;
        }
        if ($subtype === 'Launch') {
            $codes[PreflightCode::LaunchAction->value] = true;
        }

        if ($graph->dictEntryAsName($dict, 'Type') === 'Sig') {
            $codes[PreflightCode::AlreadySigned->value] = true;
        }

        if (array_key_exists('ByteRange', $dict) && array_key_exists('Contents', $dict)) {
            $codes[PreflightCode::AlreadySigned->value] = true;
        }

        // /SigFlags bit 1 (value 1) means the document already has at least one signature.
        $sigFlags = $graph->dictEntryAsInt($dict, 'SigFlags');
        if ($sigFlags !== null && ($sigFlags & 1) === 1) {
            $codes[PreflightCode::AlreadySigned->value] = true;
        }

        if ($graph->dictEntryAsName($dict, 'FT') === 'Sig' && array_key_exists('V', $dict)) {
            $codes[PreflightCode::AlreadySigned->value] = true;
        }
    }

    /** @param array<int, PreflightFinding> $findings */
    private function hasRejection(array $findings): bool
    {
        foreach ($findings as $finding) {
            if ($finding->severity === PreflightSeverity::Reject) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, PreflightFinding>  $findings
     * @param  array<int, PageGeometry>  $pages
     */
    private function report(
        array $findings,
        array $pages,
        string $pdfBytes,
        int $objectCount,
        PreflightBudget $budget,
    ): PreflightReport {
        return new PreflightReport(
            $findings,
            $pages,
            new DocumentMetrics(
                strlen($pdfBytes),
                count($pages),
                $objectCount,
                $budget->elapsedSeconds(),
                $budget->memoryDeltaBytes(),
            ),
        );
    }
}
