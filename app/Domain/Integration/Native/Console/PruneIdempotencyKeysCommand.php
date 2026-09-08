<?php

declare(strict_types=1);

namespace App\Domain\Integration\Native\Console;

use App\Domain\Integration\Native\IdempotencyStore;
use Illuminate\Console\Command;

/**
 * Remove idempotency keys past their 24-hour window.
 *
 * Scheduled hourly in routes/console.php. The table is operational scratch: without a prune
 * it grows with every mutating API call forever, and the replay guarantee only ever covers a
 * day, so nothing of value is lost.
 */
final class PruneIdempotencyKeysCommand extends Command
{
    protected $signature = 'esign:api:prune-idempotency-keys';

    protected $description = 'Delete native API idempotency keys older than their replay window (24 hours).';

    public function handle(IdempotencyStore $store): int
    {
        $removed = $store->prune();

        $this->info($removed === 0
            ? 'No expired idempotency keys.'
            : 'Removed '.$removed.' expired idempotency key'.($removed === 1 ? '' : 's').'.');

        return self::SUCCESS;
    }
}
