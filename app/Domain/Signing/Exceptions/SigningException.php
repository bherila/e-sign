<?php

declare(strict_types=1);

namespace App\Domain\Signing\Exceptions;

use RuntimeException;

/**
 * Base class for every refusal the signing state machine makes.
 *
 * Each subclass is a distinct, catchable reason, because the surfaces above this module have
 * to translate them into different answers: the Firma facade owes a `409` for one and a
 * `400` for another, and the guest signing UI has to tell a person to re-read a document
 * rather than showing them a generic failure. A single exception with a message string would
 * push that decision into string matching.
 *
 * `code()` is a stable machine-readable identifier. Renaming one is a breaking change to the
 * API surface, exactly like the field-schema validation codes.
 */
abstract class SigningException extends RuntimeException
{
    /** A stable identifier for this refusal. Part of the API surface. */
    abstract public function code(): string;
}
