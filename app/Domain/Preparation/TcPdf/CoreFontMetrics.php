<?php

declare(strict_types=1);

namespace App\Domain\Preparation\TcPdf;

use App\Domain\Preparation\Assembly\AssemblyException;

/**
 * The one text face this application draws with, and the only font metrics it ships.
 *
 * ## Why there is a metrics file at all
 *
 * tc-lib-pdf resolves *every* font — including the PDF standard 14 — through a generated
 * `<family>.json` metrics file, and the Composer dist package ships none of them: they are a
 * `make fonts` build artifact of `tecnickcom/tc-lib-pdf-font`, produced from Adobe AFM
 * sources that are not a dependency. `tests/Support/SealingFixtures::syntheticPdf()` records
 * the same finding, and `FontDictionaryReader` refuses a standard-14 font for the same
 * reason. So "use the core fonts" is not available for free: without a metrics file the
 * engine cannot lay a single character out.
 *
 * ## Why Courier, and why that is honest
 *
 * `resources/fonts/courier.json` is committed, and it is *this repository's* file rather
 * than a redistributed one. That is only defensible because Courier is fixed pitch: every
 * glyph advances 600/1000 em, which is a defining property of the face, not a table of
 * measurements someone had to copy. The file therefore states one fact 224 times, and it
 * can be regenerated from that fact alone.
 *
 * Helvetica and Times are not offered here on purpose. Their widths are a per-glyph table
 * that cannot be derived, only transcribed, and transcribing metrics from memory is exactly
 * the kind of guess AGENTS.md rules out for coordinates and that would be no better here: a
 * wrong advance silently mis-positions every subsequent character on the line.
 *
 * The PDF that comes out carries only `/BaseFont /Courier` with `/WinAnsiEncoding` and no
 * `/Widths` array, which is the canonical standard-14 declaration — every conforming viewer
 * already has the face. The JSON is consumed by the layout engine in this process and never
 * embedded, so nothing about the artifact depends on it being present at read time.
 *
 * ## Line width
 *
 * Because the face is fixed pitch, {@see width()} is exact rather than an estimate, which is
 * what lets the executed-document renderer wrap a text field's value inside its declared
 * rectangle instead of letting it run past the edge.
 */
final class CoreFontMetrics
{
    /** The tc-lib-pdf family key; the file is `<family>.json`. */
    public const FAMILY = 'courier';

    /** Advance width of every glyph, in 1/1000 em. Fixed pitch: this is the whole metric. */
    public const ADVANCE_PER_EM = 600;

    /** Advance of one character as a fraction of the font size. */
    public const ADVANCE_RATIO = self::ADVANCE_PER_EM / 1000;

    /**
     * Cap height as a fraction of the font size, used to centre a line inside a field rect.
     *
     * Courier's cap height is 562/1000 em (its `/CapHeight`), so a line of capitals occupies
     * that much of the size above the baseline.
     */
    public const CAP_HEIGHT_RATIO = 0.562;

    /** Resolved once: defining a constant twice is fatal, and the path never changes. */
    private static ?string $installedDirectory = null;

    /**
     * Make the bundled metrics visible to the PDF engine.
     *
     * tc-lib-pdf-font looks for a definition file in `K_PATH_FONTS` and refuses to read one
     * from outside the directories that constant admits, so the constant is how a bundled
     * metrics file is reachable at all. It is defined lazily rather than at boot because a
     * request that never draws text should not have to care where the fonts are, and it is
     * guarded because a second, different directory would silently be ignored — the caller
     * would then get a font it did not ask for, which is worse than an error.
     *
     * @throws AssemblyException When a different font directory is already in force.
     */
    public static function install(string $directory): void
    {
        $directory = rtrim($directory, '/\\');

        if (defined('K_PATH_FONTS')) {
            $current = rtrim((string) constant('K_PATH_FONTS'), '/\\');

            if ($current !== $directory) {
                throw new AssemblyException(
                    'The PDF font directory is already fixed to a different path for this process. '
                    .'The engine resolves it through a constant, so it can only be set once.',
                );
            }

            self::$installedDirectory = $current;

            return;
        }

        if (! is_file($directory.DIRECTORY_SEPARATOR.self::FAMILY.'.json')) {
            throw new AssemblyException(
                'No '.self::FAMILY.'.json metrics file was found in the configured font directory, '
                .'so no text can be laid out. Text is refused rather than dropped.',
            );
        }

        define('K_PATH_FONTS', $directory);
        self::$installedDirectory = $directory;
    }

    /** True once {@see install()} has run in this process. */
    public static function isInstalled(): bool
    {
        return self::$installedDirectory !== null;
    }

    /** Width of a string in points at a given font size. Exact, because the face is fixed pitch. */
    public static function width(string $text, float $fontSize): float
    {
        return mb_strlen($text, 'UTF-8') * $fontSize * self::ADVANCE_RATIO;
    }

    /**
     * The largest font size at which `$text` fits inside `$width`, capped at `$preferred`.
     *
     * Shrinking rather than truncating: a value a signer supplied has to appear in full, and
     * a clipped one reads as a different value.
     */
    public static function fittedSize(string $text, float $width, float $preferred, float $minimum = 5.0): float
    {
        $characters = mb_strlen($text, 'UTF-8');

        if ($characters === 0 || $width <= 0.0) {
            return $preferred;
        }

        return max($minimum, min($preferred, $width / ($characters * self::ADVANCE_RATIO)));
    }

    /**
     * Break `$text` into lines that each fit inside `$width` at `$fontSize`.
     *
     * Wraps on spaces, and hard-splits a single word longer than the line rather than
     * letting it overflow. Existing newlines are honoured.
     *
     * @return list<string>
     */
    public static function wrap(string $text, float $width, float $fontSize): array
    {
        $perLine = $fontSize > 0.0 ? (int) floor($width / ($fontSize * self::ADVANCE_RATIO)) : 0;

        if ($perLine < 1) {
            return $text === '' ? [] : [$text];
        }

        $lines = [];

        foreach (preg_split('/\R/u', $text) ?: [] as $paragraph) {
            $current = '';

            foreach (preg_split('/ +/u', $paragraph) ?: [] as $word) {
                while (mb_strlen($word, 'UTF-8') > $perLine) {
                    if ($current !== '') {
                        $lines[] = $current;
                        $current = '';
                    }

                    $lines[] = mb_substr($word, 0, $perLine, 'UTF-8');
                    $word = mb_substr($word, $perLine, null, 'UTF-8');
                }

                $candidate = $current === '' ? $word : $current.' '.$word;

                if (mb_strlen($candidate, 'UTF-8') > $perLine) {
                    $lines[] = $current;
                    $current = $word;

                    continue;
                }

                $current = $candidate;
            }

            $lines[] = $current;
        }

        return array_values($lines);
    }
}
