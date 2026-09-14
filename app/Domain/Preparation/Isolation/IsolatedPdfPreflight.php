<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Isolation;

use App\Domain\Preparation\Contracts\DocumentReadUnavailable;
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
 * The port promises a report for any document. So a read the runtime stopped is a rejection naming
 * that ceiling, exactly as an in-process read would have reported it. A child that failed some other
 * way learned nothing about the document, so that is not a report at all: it is the service-side
 * failure every document-read port declares, {@see DocumentReadUnavailable}.
 */
final readonly class IsolatedPdfPreflight implements PdfPreflight
{
    public function __construct(
        private PdfPreflight $inProcess,
        private DocumentIsolation $isolation,
        private PreflightLimits $limits,
    ) {}

    /**
     * @throws DocumentReadUnavailable When the read fails on the service side: its child process, or isolation that is required and unavailable.
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
        } catch (ChildReadFailed $failed) {
            throw DocumentReadUnavailable::childFailed($failed);
        }

        $report = $response['value'] ?? null;

        if (($response['ok'] ?? false) === true && $report instanceof PreflightReport) {
            return $report;
        }

        // In-process preflight reports on every document rather than throwing, so anything other
        // than a report is the child failing, not the document.
        throw new DocumentReadUnavailable('The document read process answered preflight with something other than a report.');
    }

    public function forGenerated(): PdfPreflight
    {
        return new self($this->inProcess->forGenerated(), $this->isolation, $this->limits->forGenerated());
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
