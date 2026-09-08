<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Events\Console;

use App\Domain\Delivery\Events\ReminderScheduler;
use Illuminate\Console\Command;

/**
 * The daily nudge. Scheduled in `routes/console.php`; safe to run by hand at any time.
 *
 * Every condition it applies is a fact about a row rather than about the schedule, so an
 * extra run sends nothing extra. That matters on the cPanel profile, where cron can overlap
 * itself, and it is why the command has no "--since" argument to get wrong.
 */
final class RemindCommand extends Command
{
    protected $signature = 'esign:signing:remind';

    protected $description = 'Remind recipients who are still being waited on.';

    public function handle(ReminderScheduler $scheduler): int
    {
        $reminded = $scheduler->run();

        $this->components->info(
            $reminded === []
                ? 'No recipient is due a reminder.'
                : 'Queued '.count($reminded).' reminder(s).'
        );

        return self::SUCCESS;
    }
}
