<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Webhooks\Exceptions;

use RuntimeException;

/**
 * An outbox write was attempted with no database transaction open.
 *
 * The outbox is only worth having if the event and the domain transition it
 * describes commit or roll back together (docs/HANDOFF.md §11). Writing one
 * outside a transaction produces exactly the two failures the pattern exists to
 * prevent: an event for a transition that never happened, and a transition
 * whose event was lost. It is refused rather than allowed with a warning.
 */
final class OutboxWriteOutsideTransactionException extends RuntimeException {}
