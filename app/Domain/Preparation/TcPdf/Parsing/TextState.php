<?php

declare(strict_types=1);

namespace App\Domain\Preparation\TcPdf\Parsing;

/**
 * Mutable text state for one content stream, as defined by ISO 32000-1 9.3.
 *
 * @internal Used only by the text locator while walking a content stream.
 */
final class TextState
{
    public Matrix $textMatrix;

    public Matrix $lineMatrix;

    public string $fontResource = '';

    public float $fontSize = 0.0;

    public float $charSpacing = 0.0;

    public float $wordSpacing = 0.0;

    public float $horizontalScale = 1.0;

    public float $leading = 0.0;

    public float $rise = 0.0;

    public function __construct()
    {
        $this->textMatrix = Matrix::identity();
        $this->lineMatrix = Matrix::identity();
    }

    public function translateLine(float $tx, float $ty): void
    {
        $this->lineMatrix = Matrix::translation($tx, $ty)->multiply($this->lineMatrix);
        $this->textMatrix = $this->lineMatrix;
    }
}
