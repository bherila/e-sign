<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Events\Console;

use App\Domain\Delivery\Events\EnvelopeExpirer;
use Illuminate\Console\Command;

/**
 * Applies expiries that have arrived. Scheduled in `routes/console.php`.
 *
 * It cannot expire anything early: `EnvelopeStateMachine::expire()` re-checks the deadline
 * under a lock and refuses an envelope that is not due, so this command is a prompt rather
 * than an authority. An operator who wants to stop an envelope before its deadline is
 * cancelling it, which records a reason and says so.
 */
final class ExpireCommand extends Command
{
    protected $signature = 'esign:signing:expire';

    protected $description = 'Expire envelopes whose signing window has passed.';

    public function handle(EnvelopeExpirer $expirer): int
    {
        $expired = $expirer->run();

        $this->components->info(
            $expired === []
                ? 'No envelope is past its expiry.'
                : 'Expired '.count($expired).' envelope(s).'
        );

        return self::SUCCESS;
    }
}
