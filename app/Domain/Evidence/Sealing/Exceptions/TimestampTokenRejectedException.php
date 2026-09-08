<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Sealing\Exceptions;

/**
 * The TSA answered, but the token it returned was refused.
 *
 * The token check lives in tc-lib-pdf-sign and covers the TSA signature, the
 * timestamping extended key usage, the message imprint, the echoed nonce, the
 * requested policy, and the genTime window. A token failing any of these is
 * never embedded.
 */
final class TimestampTokenRejectedException extends SealingException {}
