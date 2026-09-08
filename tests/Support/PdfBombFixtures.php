<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Access to the resource-exhaustion fixture corpus and its manifest.
 *
 * Kept apart from {@see PdfFixtures} because these files are not part of the Stage 0
 * classification matrix: they exist to be refused, and the geometry tests that iterate the
 * main manifest have nothing to say about them.
 *
 * Regenerate with `php tests/Fixtures/pdf/generate-bombs.php`.
 */
final class PdfBombFixtures
{
    public static function directory(): string
    {
        return dirname(__DIR__).'/Fixtures/pdf/bombs';
    }

    public static function bytes(string $name): string
    {
        $path = self::directory().'/'.$name.'.pdf';
        $bytes = @file_get_contents($path);

        if ($bytes === false) {
            throw new \RuntimeException(
                'Missing fixture '.$path.'. Run: php tests/Fixtures/pdf/generate-bombs.php',
            );
        }

        return $bytes;
    }

    /** @return array<int, array<string, mixed>> */
    public static function manifest(): array
    {
        $path = self::directory().'/manifest.json';
        $json = @file_get_contents($path);

        if ($json === false) {
            throw new \RuntimeException('Missing '.$path.'. Run: php tests/Fixtures/pdf/generate-bombs.php');
        }

        /** @var array<int, array<string, mixed>> $decoded */
        $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /** @return array<string, array<string, mixed>> Keyed by fixture name. */
    public static function byName(): array
    {
        $out = [];
        foreach (self::manifest() as $entry) {
            /** @var string $name */
            $name = $entry['name'];
            $out[$name] = $entry;
        }

        return $out;
    }

    /**
     * PHPUnit data provider over the whole corpus: [fixture name, manifest entry].
     *
     * @return \Generator<string, array{string, array<string, mixed>}>
     */
    public static function all(): \Generator
    {
        foreach (self::manifest() as $entry) {
            /** @var string $name */
            $name = $entry['name'];
            yield $name => [$name, $entry];
        }
    }
}
