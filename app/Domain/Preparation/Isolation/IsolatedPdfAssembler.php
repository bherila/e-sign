<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Isolation;

use App\Domain\Preparation\Assembly\AssembledDocument;
use App\Domain\Preparation\Assembly\AssemblyException;
use App\Domain\Preparation\Assembly\PageOverlay;
use App\Domain\Preparation\Assembly\UnsupportedSourceException;
use App\Domain\Preparation\Contracts\DocumentReadUnavailable;
use App\Domain\Preparation\Contracts\PdfAssembler;
use App\Domain\Preparation\Preflight\PreflightBudgetException;
use App\Domain\Preparation\Preflight\PreflightCode;
use App\Domain\Preparation\Preflight\PreflightLimits;
use App\Domain\Preparation\TcPdf\TcPdfAssembler;

/**
 * Assembly, in a child process when this host provides one (docs/adr/0006).
 *
 * The whole assembly runs in one child — its preflight of every input, the geometry reads, the
 * import and the read-back — so one hard limit covers all of it. That limit is sized for every read
 * the assembly budgets separately ({@see budgetedReads()}), not for one: in-process, each of those
 * reads may spend a whole budget, and a child held to a single one would be stopped — after assent,
 * at finalization — doing work the in-process path completes. A ceiling leaves as the
 * `AssemblyException` the port promises, carrying the ceiling, as the in-process assembler does.
 */
final readonly class IsolatedPdfAssembler implements PdfAssembler
{
    public function __construct(
        private PdfAssembler $inProcess,
        private DocumentIsolation $isolation,
        private PreflightLimits $limits,
        private string $fontDirectory,
    ) {}

    /**
     * @param  array<int, PageOverlay>  $overlays
     * @param  array<int, string>  $appendedDocuments
     *
     * @throws DocumentReadUnavailable When the read fails on the service side: its child process, or isolation that is required and unavailable.
     */
    public function assemble(string $pdfBytes, array $overlays = [], array $appendedDocuments = []): AssembledDocument
    {
        $reader = $this->isolation->reader();

        if (! $reader instanceof ChildProcessDocumentReader) {
            return $this->inProcess->assemble($pdfBytes, $overlays, $appendedDocuments);
        }

        try {
            $response = $reader->read(
                [
                    'operation' => ChildDocumentRead::ASSEMBLE,
                    'bytes' => $pdfBytes,
                    'overlays' => array_values($overlays),
                    'appended' => array_values($appendedDocuments),
                    'fonts' => $this->fontDirectory,
                ],
                $this->limits,
                self::budgetedReads(count($appendedDocuments)),
            );
        } catch (PreflightBudgetException $stopped) {
            throw new AssemblyException(
                'The document could not be re-assembled within this deployment\'s limits: '.$stopped->getMessage(),
                previous: $stopped,
            );
        } catch (ChildReadFailed $failed) {
            // Not an AssemblyException: that says these bytes cannot be re-assembled, and finalization
            // gives up on it. A child that failed learned nothing about the bytes.
            throw DocumentReadUnavailable::childFailed($failed);
        }

        $assembled = $response['value'] ?? null;

        if (($response['ok'] ?? false) === true && $assembled instanceof AssembledDocument) {
            return $assembled;
        }

        $message = (string) ($response['message'] ?? 'The document could not be re-assembled.');

        if (($response['kind'] ?? null) === 'unsupported') {
            throw new UnsupportedSourceException($message);
        }

        $ceiling = is_string($response['code'] ?? null)
            ? new PreflightBudgetException(PreflightCode::from($response['code']), $message, ($response['per_stream'] ?? false) === true)
            : null;

        throw new AssemblyException($message, previous: $ceiling);
    }

    /**
     * The reads {@see TcPdfAssembler::assemble()} gives a budget of their own.
     *
     * The source's preflight and each appended document's; the source's geometry read and import,
     * which share one budget, and each appended document's; and the read-back of the output.
     */
    private static function budgetedReads(int $appended): int
    {
        return 3 + 2 * max(0, $appended);
    }
}
