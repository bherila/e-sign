<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Isolation;

use App\Domain\Preparation\Contracts\PdfPreflight;
use App\Domain\Preparation\Preflight\DocumentMetrics;
use App\Domain\Preparation\Preflight\PreflightBudgetException;
use App\Domain\Preparation\Preflight\PreflightCode;
use App\Domain\Preparation\Preflight\PreflightFinding;
use App\Domain\Preparation\Preflight\PreflightLimits;
use App\Domain\Preparation\Preflight\PreflightReport;

/**
 * Preflight, in a child process when this host provides one (docs/adr/0006).
 *
 * The port promises a report for any document, never an exception. So a read the runtime stopped
 * is a rejection naming that ceiling, and a child that failed some other way is `unparseable`, both
 * exactly as an in-process read would have reported them.
 */
final readonly class IsolatedPdfPreflight implements PdfPreflight
{
    public function __construct(
        private PdfPreflight $inProcess,
        private DocumentIsolation $isolation,
        private PreflightLimits $limits,
    ) {}

    /**
     * @throws DocumentIsolationUnavailable When isolation is required and this host cannot provide it.
     */
    public function inspect(string $pdfBytes): PreflightReport
    {
        $reader = $this->isolation->reader();

        if (! $reader instanceof ChildProcessDocumentReader) {
            return $this->inProcess->inspect($pdfBytes);
        }

        $startedAt = microtime(true);

        try {
            $response = $reader->read(['operation' => ChildDocumentRead::PREFLIGHT, 'bytes' => $pdfBytes], $this->limits);
        } catch (PreflightBudgetException $stopped) {
            return $this->rejected($pdfBytes, $stopped->preflightCode, $stopped->getMessage(), $startedAt);
        } catch (ChildReadFailed) {
            return $this->unparseable($pdfBytes, $startedAt);
        }

        $report = $response['value'] ?? null;

        if (($response['ok'] ?? false) === true && $report instanceof PreflightReport) {
            return $report;
        }

        // In-process preflight reports on every document rather than throwing, so anything other
        // than a report is the child failing, not the document.
        return $this->unparseable($pdfBytes, $startedAt);
    }

    public function forGenerated(): PdfPreflight
    {
        return new self($this->inProcess->forGenerated(), $this->isolation, $this->limits->forGenerated());
    }

    private function unparseable(string $pdfBytes, float $startedAt): PreflightReport
    {
        return $this->rejected(
            $pdfBytes,
            PreflightCode::Unparseable,
            'The file could not be read as a PDF. Re-export the document from the application that produced it '
            .'and upload it again.',
            $startedAt,
        );
    }

    private function rejected(string $pdfBytes, PreflightCode $code, string $message, float $startedAt): PreflightReport
    {
        return new PreflightReport(
            [PreflightFinding::reject($code, $message)],
            [],
            new DocumentMetrics(strlen($pdfBytes), 0, 0, microtime(true) - $startedAt, 0),
        );
    }
}
