<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Retention\Exceptions;

use RuntimeException;

/**
 * Something tried to reach the outside world from inside a restore drill.
 *
 * The drill copy holds the production database: real recipient addresses, real webhook
 * URLs, and queued work aimed at both. Nothing in the restored data says "you are a copy",
 * so the environment does, and every outbound path asks before it acts.
 *
 * Deliberately an exception rather than a silent no-op. A suppressed send that is recorded
 * as sent would make the drill's own mail rows lie, and the point of the drill is to find
 * out what the restored instance actually does.
 */
final class RestoreDrillActive extends RuntimeException
{
    public static function refusing(string $what): self
    {
        return new self(sprintf(
            'Refusing %s: ESIGN_RESTORE_DRILL is set, so this instance is a restored copy and must '
            .'not contact anyone. Unset it only on the instance that is genuinely serving traffic.',
            $what,
        ));
    }
}
