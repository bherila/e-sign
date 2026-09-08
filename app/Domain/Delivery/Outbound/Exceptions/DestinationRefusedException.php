<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Outbound\Exceptions;

use RuntimeException;

/**
 * An outbound destination was refused before any packet left the process.
 *
 * Raised for a malformed URL, an unsupported scheme, plaintext HTTP without an
 * explicit administrator opt-in, embedded credentials, or a host that resolves
 * to a loopback, private, link-local, or otherwise reserved address that no
 * allowlist entry permits.
 */
final class DestinationRefusedException extends RuntimeException {}
