<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Sealing\Exceptions;

/**
 * The configured seal material was read but cannot be used.
 *
 * Raised for an unparsable certificate or key, a private key whose passphrase
 * is wrong, a certificate that is expired or not yet valid, and a private key
 * that does not match the certificate's public key.
 */
final class SealMaterialInvalidException extends SealingException {}
