<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Geometry;

use RuntimeException;

/**
 * Raised when a compatibility request carries coordinates without declaring which
 * convention they are in. Guessing from magnitude is explicitly forbidden.
 */
final class UndeclaredCoordinateConventionException extends RuntimeException
{
    public static function forProfile(string $profile): self
    {
        return new self(sprintf(
            'Profile "%s" did not declare a coordinate convention. Field coordinates cannot be '
            .'interpreted without one, and the convention is never inferred from the values. '
            .'Declare one of: %s.',
            $profile,
            implode(', ', array_column(DeclaredCoordinateConvention::cases(), 'value')),
        ));
    }
}
