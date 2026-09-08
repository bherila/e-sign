<?php

declare(strict_types=1);

namespace App\Domain\Identity\Credentials;

use RuntimeException;

/**
 * The authenticated principal does not hold a scope the operation requires.
 *
 * This is an authorization failure, not an authentication one: the credential is valid and
 * the caller is who they claim to be. HTTP callers get 403 from
 * App\Http\Middleware\RequireScope before a controller runs; this exception exists for
 * domain services reached from somewhere other than a scoped route, so that a missing check
 * fails closed rather than returning data.
 */
final class MissingScope extends RuntimeException
{
    /**
     * @param  list<Scope>  $granted
     */
    public static function for(Scope $required, array $granted): self
    {
        return new self(sprintf(
            "This credential is not granted '%s'. Granted: %s.",
            $required->value,
            $granted === []
                ? 'nothing'
                : implode(', ', array_map(static fn (Scope $scope): string => $scope->value, $granted)),
        ));
    }
}
