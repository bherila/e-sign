<?php

declare(strict_types=1);

namespace App\Domain\Identity\Credentials;

use InvalidArgumentException;

/**
 * A scope string that is not a Scope case was offered at issue time.
 *
 * Refusing is the point: silently dropping an unrecognised scope would issue a credential
 * that does less than the operator asked for, and silently keeping it would put a value in
 * the database that no enforcement path can ever check.
 */
final class UnknownScope extends InvalidArgumentException
{
    /**
     * @param  list<string>  $unknown
     */
    public static function for(array $unknown): self
    {
        return new self(sprintf(
            'Unknown %s: %s. Known scopes: %s.',
            count($unknown) === 1 ? 'scope' : 'scopes',
            implode(', ', array_map(static fn (string $value): string => "'{$value}'", $unknown)),
            implode(', ', Scope::values()),
        ));
    }
}
