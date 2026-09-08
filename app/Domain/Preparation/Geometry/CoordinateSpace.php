<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Geometry;

/**
 * The single native coordinate space used by the editor, the native API and final
 * assembly. Serialised into every field-definition document so a stored rectangle
 * can never be reinterpreted under a different convention later.
 */
final readonly class CoordinateSpace
{
    public const UNIT = 'pt';

    public const ORIGIN = 'top-left';

    public const PAGE_BOX = 'crop';

    public const ROTATION = 'displayed';

    public const PAGE_INDEX_BASE = 1;

    /** @return array<string, string|int> */
    public static function descriptor(): array
    {
        return [
            'unit' => self::UNIT,
            'origin' => self::ORIGIN,
            'page_box' => self::PAGE_BOX,
            'rotation' => self::ROTATION,
            'page_index_base' => self::PAGE_INDEX_BASE,
        ];
    }

    /**
     * @param  array<string, mixed>  $descriptor
     *
     * @throws InvalidGeometryException When the document declares a space we do not implement.
     */
    public static function assertSupported(array $descriptor): void
    {
        foreach (self::descriptor() as $key => $expected) {
            if (! array_key_exists($key, $descriptor)) {
                throw new InvalidGeometryException('coordinate_space.'.$key.' must be declared explicitly.');
            }

            if ($descriptor[$key] !== $expected) {
                throw new InvalidGeometryException(sprintf(
                    'Unsupported coordinate_space.%s: expected %s, got %s.',
                    $key,
                    var_export($expected, true),
                    var_export($descriptor[$key], true),
                ));
            }
        }
    }
}
