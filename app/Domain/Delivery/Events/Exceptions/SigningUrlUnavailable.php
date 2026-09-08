<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Events\Exceptions;

use RuntimeException;

/**
 * No signing link could be issued, so no invitation is sent.
 *
 * Thrown rather than swallowed. The message that would have gone out is not written to the
 * outbox at all, which keeps the mail history honest: there is no `queued` row claiming a
 * signer was invited when they were not.
 */
final class SigningUrlUnavailable extends RuntimeException {}
