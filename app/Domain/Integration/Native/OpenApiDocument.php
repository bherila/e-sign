<?php

declare(strict_types=1);

namespace App\Domain\Integration\Native;

use JsonException;
use RuntimeException;

/**
 * The committed OpenAPI 3.1 description of `/api/v1`, and the one place it is read.
 *
 * ## Why the source is JSON and not YAML
 *
 * OpenAPI is usually hand-written in YAML, and this document was going to be. It is JSON
 * because there is no YAML parser in this application's runtime and adding one — a
 * production dependency, a licence entry, a supply-chain surface — to translate a static
 * file on the way out would be a poor trade for nicer quoting. JSON also means the committed
 * file is the artifact: Redoc, Swagger UI, and every client generator read
 * `resources/api/openapi-v1.json` straight from the repository without running the
 * application, and `GET /api/v1/openapi.json` serves those same bytes rather than a
 * re-encoding of them.
 *
 * ## Why it is hand-written and still cannot drift
 *
 * Nothing generates this file, and nothing has to: a contract test
 * (tests/Feature/Integration/Native/OpenApiContractTest.php) asserts both directions —
 * every registered `/api/v1` route and method appears in the document, and every path and
 * method the document describes exists in the router. A route added without a description,
 * or a description left behind by a deleted route, fails the suite. That is the property a
 * generator would have bought, without a generator's habit of emitting schemas nobody read.
 */
final class OpenApiDocument
{
    public const PATH = 'api/openapi-v1.json';

    /** Cached across a request; the file cannot change under a running process. */
    private static ?string $raw = null;

    /** The document exactly as committed, ready to send. */
    public static function json(): string
    {
        if (self::$raw !== null) {
            return self::$raw;
        }

        $path = resource_path(self::PATH);
        $contents = is_readable($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            throw new RuntimeException('The OpenAPI document is missing from resources/'.self::PATH.'.');
        }

        return self::$raw = $contents;
    }

    /**
     * @return array<string, mixed>
     */
    public static function toArray(): array
    {
        try {
            $decoded = json_decode(self::json(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'The OpenAPI document in resources/'.self::PATH.' is not valid JSON.',
                previous: $exception,
            );
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Every `METHOD /api/v1/path` the document describes, as a sorted list.
     *
     * The path keys in the document are relative to the server (`/templates`), so they are
     * qualified here with the prefix the router mounts them under. One place decides that
     * mapping, so the contract test compares like with like.
     *
     * @return list<string>
     */
    public static function operations(): array
    {
        $operations = [];
        $paths = self::toArray()['paths'] ?? [];

        foreach (is_array($paths) ? $paths : [] as $path => $methods) {
            foreach (is_array($methods) ? $methods : [] as $method => $operation) {
                if (in_array(strtolower((string) $method), ['get', 'post', 'patch', 'put', 'delete'], true)) {
                    $operations[] = strtoupper((string) $method).' /api/v1'.$path;
                }
            }
        }

        sort($operations);

        return $operations;
    }
}
