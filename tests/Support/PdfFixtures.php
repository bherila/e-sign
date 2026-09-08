<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Access to the committed Stage 0 fixture matrix and its manifest.
 *
 * Regenerate the fixtures and the manifest with `php tests/Fixtures/pdf/generate.php`.
 */
final class PdfFixtures
{
    public static function directory(): string
    {
        return dirname(__DIR__).'/Fixtures/pdf';
    }

    public static function bytes(string $name): string
    {
        $path = self::directory().'/'.$name.'.pdf';
        $bytes = @file_get_contents($path);

        if ($bytes === false) {
            throw new \RuntimeException(
                'Missing fixture '.$path.'. Run: php tests/Fixtures/pdf/generate.php',
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
            throw new \RuntimeException('Missing '.$path.'. Run: php tests/Fixtures/pdf/generate.php');
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
     * PHPUnit data provider over the whole matrix: [fixture name, manifest entry].
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

    /**
     * Only the fixtures preflight is expected to accept.
     *
     * @return \Generator<string, array{string, array<string, mixed>}>
     */
    public static function accepted(): \Generator
    {
        foreach (self::manifest() as $entry) {
            if ($entry['expected_preflight'] !== 'accept') {
                continue;
            }

            /** @var string $name */
            $name = $entry['name'];
            yield $name => [$name, $entry];
        }
    }
}
