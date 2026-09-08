<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Webhooks\Exceptions;

use InvalidArgumentException;

/**
 * An event name that is neither a documented profile event nor one of ours.
 */
final class UnknownEventNameException extends InvalidArgumentException {}
