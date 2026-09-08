<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * Access to the synthetic field-schema fixtures shared by the PHP and TypeScript suites.
 *
 * `tests/Fixtures/schema/nda-two-signers.json` is the canonical example: both suites import the
 * same bytes, so a divergence between the PHP importer and the editor is a test failure rather
 * than a production surprise. Synthetic only — no real agreement, name, or address (AGENTS.md).
 */
final class FieldSchemaFixture
{
    public const NDA_TWO_SIGNERS = 'nda-two-signers.json';

    /** Letter portrait, the size the NDA fixture is placed against. */
    public const LETTER_WIDTH = 612.0;

    public const LETTER_HEIGHT = 792.0;

    public static function path(string $name = self::NDA_TWO_SIGNERS): string
    {
        return dirname(__DIR__).'/Fixtures/schema/'.$name;
    }

    public static function json(string $name = self::NDA_TWO_SIGNERS): string
    {
        $raw = file_get_contents(self::path($name));

        if ($raw === false) {
            throw new RuntimeException('Could not read field schema fixture '.$name.'.');
        }

        return $raw;
    }

    /**
     * The fixture decoded, with its property order intact.
     *
     * @return array<string, mixed>
     */
    public static function asArray(string $name = self::NDA_TWO_SIGNERS): array
    {
        $decoded = json_decode(self::json($name), true, 64, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException('Field schema fixture '.$name.' is not a JSON object.');
        }

        /** @var array<string, mixed> */
        return $decoded;
    }

    /** The TypeScript mirror of the schema, read as source so the two vocabularies can be pinned. */
    public static function typescriptMirrorSource(): string
    {
        $path = dirname(__DIR__, 2).'/resources/js/schema/fieldSchema.ts';
        $raw = file_get_contents($path);

        if ($raw === false) {
            throw new RuntimeException('Could not read '.$path.'; it is the editor half of the field schema contract.');
        }

        return $raw;
    }

    /** The published JSON Schema, decoded. */
    public static function schemaPath(): string
    {
        return dirname(__DIR__, 2).'/resources/schema/field-schema-1.0.json';
    }

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        $raw = file_get_contents(self::schemaPath());

        if ($raw === false) {
            throw new RuntimeException('Could not read resources/schema/field-schema-1.0.json.');
        }

        $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException('resources/schema/field-schema-1.0.json is not a JSON object.');
        }

        /** @var array<string, mixed> */
        return $decoded;
    }
}
