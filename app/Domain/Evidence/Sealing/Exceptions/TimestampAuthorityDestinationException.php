<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Sealing\Exceptions;

/**
 * The configured TSA URL is refused by the destination policy.
 *
 * Raised before any packet leaves the process: a malformed URL, an
 * unsupported scheme, plaintext HTTP without the explicit opt-in, embedded
 * credentials, or a host that resolves to a loopback, private, link-local,
 * or otherwise reserved address.
 */
final class TimestampAuthorityDestinationException extends SealingException {}
