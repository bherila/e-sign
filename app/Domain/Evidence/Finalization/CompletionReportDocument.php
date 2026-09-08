<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Finalization;

use App\Domain\Evidence\Finalization\Exceptions\FinalizationException;
use App\Domain\Preparation\TcPdf\CoreFontMetrics;
use Com\Tecnick\Pdf\Tcpdf;
use Throwable;

/**
 * Renders a {@see CompletionReport} to a standalone PDF.
 *
 * One page unless the content needs more, drawn in the bundled fixed-pitch face. There is no
 * import step here — there is nothing to import — so this is the one place in the Evidence
 * module that builds a document from scratch rather than through
 * `App\Domain\Preparation\Contracts\PdfAssembler`.
 *
 * The output is used twice, and that is deliberate: it is published as the
 * `completion_report` artifact, and it is handed to the assembler as an appended document so
 * the executed PDF ends with exactly the same page. Rendering it once and using the bytes in
 * both places is what makes "the report and the page agree" structural rather than a
 * convention two code paths have to keep.
 */
final readonly class CompletionReportDocument
{
    private const PAGE_WIDTH = 595.28;

    private const PAGE_HEIGHT = 841.89;

    private const MARGIN = 56.0;

    private const TITLE_SIZE = 14.0;

    private const HEADING_SIZE = 10.0;

    private const BODY_SIZE = 8.0;

    private const NOTE_SIZE = 7.5;

    private const LINE_HEIGHT = 12.0;

    public function __construct(private string $fontDirectory) {}

    /**
     * @throws FinalizationException When the engine cannot produce the page.
     */
    public function render(CompletionReport $report): string
    {
        CoreFontMetrics::install($this->fontDirectory);

        try {
            $pdf = new Tcpdf('pt', true, false, true);
            $pdf->setCreator('BWH eSign');
            $pdf->setTitle($report->title);

            $this->addPage($pdf);

            $cursor = self::MARGIN + self::TITLE_SIZE;
            $this->write($pdf, $report->title, self::MARGIN, $cursor, self::TITLE_SIZE);
            $cursor += self::LINE_HEIGHT * 1.5;

            foreach ($report->lines as $line) {
                if ($cursor > self::PAGE_HEIGHT - self::MARGIN) {
                    $this->addPage($pdf);
                    $cursor = self::MARGIN + self::LINE_HEIGHT;
                }

                if ($line['style'] === CompletionReport::STYLE_SPACER) {
                    $cursor += self::LINE_HEIGHT * 0.6;

                    continue;
                }

                $size = match ($line['style']) {
                    CompletionReport::STYLE_HEADING => self::HEADING_SIZE,
                    CompletionReport::STYLE_NOTE => self::NOTE_SIZE,
                    default => self::BODY_SIZE,
                };

                $this->write($pdf, $line['text'], self::MARGIN, $cursor, $size);
                $cursor += self::LINE_HEIGHT;
            }

            $bytes = $pdf->getOutPDFString();
        } catch (Throwable $exception) {
            throw new FinalizationException(
                'The completion report could not be rendered: '.$exception->getMessage(),
                previous: $exception,
            );
        }

        if ($bytes === '') {
            throw new FinalizationException('The completion report renderer produced no bytes.');
        }

        return $bytes;
    }

    private function addPage(Tcpdf $pdf): void
    {
        $pdf->addPage([
            'format' => '',
            'width' => self::PAGE_WIDTH,
            'height' => self::PAGE_HEIGHT,
            'orientation' => 'P',
        ]);
    }

    /**
     * Draw one line with its baseline at `$y` points below the top of the page.
     *
     * Long lines are shrunk to fit the text column rather than clipped: a digest with its
     * tail missing reads as a different digest.
     */
    private function write(Tcpdf $pdf, string $text, float $x, float $y, float $size): void
    {
        if ($text === '') {
            return;
        }

        $fitted = CoreFontMetrics::fittedSize($text, self::PAGE_WIDTH - (2 * self::MARGIN), $size);
        $pdf->font->insert($pdf->pon, CoreFontMetrics::FAMILY, '', $fitted);
        $pdf->page->addContent($pdf->getTextLine($text, $x, $y));
    }
}
