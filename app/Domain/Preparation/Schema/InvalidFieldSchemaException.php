<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Schema;

use RuntimeException;

/**
 * Thrown when a field document cannot be imported.
 *
 * It carries the full {@see ValidationResult} rather than only a message, so an HTTP adapter can
 * return every structured error to the caller. Import fails closed: there is no "import what we
 * understood and drop the rest" path.
 */
final class InvalidFieldSchemaException extends RuntimeException
{
    public function __construct(public readonly ValidationResult $result)
    {
        $count = count($result->errors);
        $first = $result->first();

        parent::__construct(sprintf(
            'Invalid native field schema document: %d %s. First: %s',
            $count,
            $count === 1 ? 'error' : 'errors',
            $first instanceof ValidationError ? $first->describe() : '(none reported)',
        ));
    }

    /**
     * @return list<ValidationError>
     */
    public function errors(): array
    {
        return $this->result->errors;
    }
}
