<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Sealing\Exceptions;

/**
 * The TSA could not be reached, or answered with something unusable.
 *
 * Transport failure, timeout, a non-200 status, an empty body, or a body that
 * is not an RFC 3161 timestamp reply.
 */
final class TimestampAuthorityUnreachableException extends SealingException {}
