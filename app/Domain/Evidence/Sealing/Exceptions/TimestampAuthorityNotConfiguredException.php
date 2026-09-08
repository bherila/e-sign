<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Sealing\Exceptions;

/**
 * PAdES B-T was requested on a deployment with no TSA configured.
 *
 * This is the explicit no-downgrade case: B-T without a timestamp authority is
 * an error, not a B-B artifact reported as a success.
 */
final class TimestampAuthorityNotConfiguredException extends SealingException {}
