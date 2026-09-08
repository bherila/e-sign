<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Schema;

use InvalidArgumentException;

/**
 * Offset from a located anchor, in the document's declared unit and orientation.
 *
 * `dy` grows downwards, like every other coordinate in the native space. Offsets may be
 * negative — placing a field above its anchor text is normal — so only finiteness is enforced.
 */
final readonly class AnchorOffset
{
    public float $dx;

    public float $dy;

    public function __construct(float $dx, float $dy)
    {
        if (! is_finite($dx) || ! is_finite($dy)) {
            throw new InvalidArgumentException('anchor.offset values must be finite numbers.');
        }

        $this->dx = CanonicalNumber::round($dx);
        $this->dy = CanonicalNumber::round($dy);
    }

    /**
     * @param  array{dx: int|float, dy: int|float}  $offset
     */
    public static function fromArray(array $offset): self
    {
        return new self((float) $offset['dx'], (float) $offset['dy']);
    }

    /**
     * @return array{dx: int|float, dy: int|float}
     */
    public function toArray(): array
    {
        return [
            'dx' => CanonicalNumber::encode($this->dx),
            'dy' => CanonicalNumber::encode($this->dy),
        ];
    }
}
