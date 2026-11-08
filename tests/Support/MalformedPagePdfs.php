<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Synthetic two-page PDFs whose second page's /Contents is, or is not, drawing instructions.
 *
 * Built here rather than committed to the Stage 0 fixture matrix: each variant differs from
 * the healthy one only in page 2's /Contents entry, which is easier to read in a test than in
 * a binary. The cross-reference table is computed from the real byte offsets, so the parser
 * reads every variant as a well-formed file and only the content defect is under test.
 */
final class MalformedPagePdfs
{
    /** Page 2 draws with its own content stream. */
    public static function healthy(): string
    {
        return self::build('/Contents 6 0 R');
    }

    /** Page 2's content is page 1's dictionary — the damage upstream describes. */
    public static function contentsPointAtAnotherPage(): string
    {
        return self::build('/Contents 3 0 R');
    }

    /** Page 2's content refers to an object the file does not contain. */
    public static function contentsPointAtNothing(): string
    {
        return self::build('/Contents 99 0 R');
    }

    /** Page 2's content array holds a dictionary among its streams. */
    public static function contentsArrayHoldsANonStream(): string
    {
        return self::build('/Contents [6 0 R 3 0 R]');
    }

    /** Page 2 has no /Contents: a legitimately blank page (ISO 32000-1 7.7.3.3). */
    public static function blankPage(): string
    {
        return self::build('');
    }

    /** Page 2 reuses page 1's content stream, which is legal. */
    public static function sharedContentStream(): string
    {
        return self::build('/Contents 5 0 R');
    }

    private static function build(string $pageTwoContents): string
    {
        $stream = "BT /F1 12 Tf 72 720 Td (Synthetic page) Tj ET\n";
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R 4 0 R] /Count 2 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 5 0 R '
                .'/Resources << /Font << /F1 7 0 R >> >> >>',
            4 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] '.$pageTwoContents
                .' /Resources << /Font << /F1 7 0 R >> >> >>',
            5 => '<< /Length '.strlen($stream)." >>\nstream\n".$stream.'endstream',
            6 => '<< /Length '.strlen($stream)." >>\nstream\n".$stream.'endstream',
            7 => '<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>',
        ];

        $pdf = "%PDF-1.7\n";
        $offsets = [];

        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= $number." 0 obj\n".$body."\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }
}
