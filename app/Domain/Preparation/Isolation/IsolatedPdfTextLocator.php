<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Isolation;

use App\Domain\Preparation\Contracts\DocumentReadUnavailable;
use App\Domain\Preparation\Contracts\PdfTextLocator;
use App\Domain\Preparation\Preflight\PreflightBudget;
use App\Domain\Preparation\Preflight\PreflightBudgetException;
use App\Domain\Preparation\Preflight\PreflightCode;
use App\Domain\Preparation\Preflight\PreflightLimits;
use App\Domain\Preparation\Text\DocumentText;
use App\Domain\Preparation\Text\TextExtractionException;
use App\Domain\Preparation\Text\TextRun;

/**
 * Text extraction, in a child process when this host provides one (docs/adr/0006).
 *
 * A caller's budget cannot cross the process boundary, so its remaining allowance does: the child
 * reads under {@see PreflightBudget::remainingLimits()}, reports what it counted, and that is
 * charged back with {@see PreflightBudget::absorb()} — after a read that succeeded and after one that
 * failed having done work, as the in-process path charges both. Who is told about a ceiling follows
 * the port's rule unchanged — a caller that supplied a budget hears the ceiling, whether the child's
 * budget named it or the runtime stopped the child; a caller that supplied none sees an unreadable
 * document.
 */
final readonly class IsolatedPdfTextLocator implements PdfTextLocator
{
    public function __construct(
        private PdfTextLocator $inProcess,
        private DocumentIsolation $isolation,
        private PreflightLimits $limits,
    ) {}

    /**
     * @return array<int, TextRun>
     *
     * @throws DocumentReadUnavailable When the read fails on the service side: its child process, or isolation that is required and unavailable.
     */
    public function extract(string $pdfBytes, ?int $page = null, ?PreflightBudget $budget = null): array
    {
        $reader = $this->isolation->reader();

        if (! $reader instanceof ChildProcessDocumentReader) {
            return $this->inProcess->extract($pdfBytes, $page, $budget);
        }

        /** @var array<int, TextRun> */
        return $this->inChild(
            $reader,
            ['operation' => ChildDocumentRead::EXTRACT, 'bytes' => $pdfBytes, 'page' => $page],
            $budget,
            static fn (mixed $value): bool => is_array($value),
        );
    }

    /**
     * @throws DocumentReadUnavailable When the read fails on the service side: its child process, or isolation that is required and unavailable.
     */
    public function read(string $pdfBytes, ?PreflightBudget $budget = null): DocumentText
    {
        $reader = $this->isolation->reader();

        if (! $reader instanceof ChildProcessDocumentReader) {
            return $this->inProcess->read($pdfBytes, $budget);
        }

        /** @var DocumentText */
        return $this->inChild(
            $reader,
            ['operation' => ChildDocumentRead::READ_TEXT, 'bytes' => $pdfBytes],
            $budget,
            static fn (mixed $value): bool => $value instanceof DocumentText,
        );
    }

    /**
     * One read in the child, under the port's rules for a caller's budget.
     *
     * Held in one place because `extract()` and `read()` are one rule: the remainder handed over,
     * the charge-back, who hears about a ceiling and in whose words. Two copies of that would drift.
     *
     * @param  array<string, mixed>  $request  The operation and its arguments.
     * @param  \Closure(mixed): bool  $expected  Whether a successful answer is the value this operation returns.
     *
     * @throws TextExtractionException
     * @throws PreflightBudgetException Only to a caller that supplied the budget.
     * @throws DocumentReadUnavailable When the child failed rather than the document.
     */
    private function inChild(
        ChildProcessDocumentReader $reader,
        array $request,
        ?PreflightBudget $budget,
        \Closure $expected,
    ): mixed {
        try {
            $response = $reader->read(
                $request + ['charged' => $budget instanceof PreflightBudget],
                $budget instanceof PreflightBudget ? $budget->remainingLimits() : $this->limits,
            );

            if (($response['ok'] ?? false) === true && $expected($response['value'] ?? null)) {
                $budget?->absorb((int) ($response['decoded'] ?? 0), (int) ($response['objects'] ?? 0));

                return $response['value'];
            }

            if (($response['kind'] ?? null) === 'budget' && is_string($response['code'] ?? null)) {
                // Not charged back: the child stopped at what remained of this budget, and the
                // refusal below is what the caller hears about it.
                throw new PreflightBudgetException(
                    PreflightCode::from($response['code']),
                    (string) ($response['message'] ?? ''),
                    ($response['per_stream'] ?? false) === true,
                );
            }

            // A document the child could not read is still a document it spent work reading.
            $budget?->absorb((int) ($response['decoded'] ?? 0), (int) ($response['objects'] ?? 0));

            throw new TextExtractionException((string) ($response['message'] ?? 'The document could not be read.'));
        } catch (PreflightBudgetException $exhausted) {
            if ($budget instanceof PreflightBudget) {
                // In the caller's budget's own words. The child read on what remained of it, so a
                // ceiling the child reached — or a hard limit derived from the remainder — is named
                // in terms of that remainder; rebuilt here it names what the deployment set.
                $budget->refuse($exhausted->preflightCode, $exhausted->getMessage(), $exhausted->perStream);
            }

            throw new TextExtractionException(
                'The document could not be read within this deployment\'s limits.',
                previous: $exhausted,
            );
        } catch (ChildReadFailed $failed) {
            throw DocumentReadUnavailable::childFailed($failed);
        }
    }
}
