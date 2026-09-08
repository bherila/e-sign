<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Retention\Console;

use App\Domain\Evidence\Retention\BlobPurger;
use App\Domain\Evidence\Retention\RetentionPolicy;
use App\Domain\Identity\Audit\AuditActor;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Contracts\Config\Repository;

/**
 * The second pass: removes the bytes of envelopes that retention soft-deleted long enough
 * ago.
 *
 * Run it on a schedule *after* `esign:retention:run`, or by hand. Splitting the two is what
 * makes an executed-document retention policy survivable: the first pass is reversible for
 * `purge_grace_days`, and this is the pass that is not.
 *
 * It selects nothing by prefix and nothing by age of an object. The only thing that makes an
 * object eligible is an `envelopes` row with `deleted_at` older than the grace period, and
 * every candidate is re-checked for a legal hold at the moment it runs — the grace period is
 * long enough for a preservation notice to arrive during it, which is the point.
 */
final class PurgeRetainedBlobsCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'esign:retention:purge-blobs
        {--dry-run : Report which objects would be removed and stop}
        {--force : Skip the confirmation prompt in production}';

    protected $description = 'Remove the artifact objects of envelopes retention soft-deleted beyond the grace period';

    public function handle(BlobPurger $purger, Repository $config): int
    {
        $policy = RetentionPolicy::fromConfig($config);
        $planned = $purger->plan($policy);

        $this->line(sprintf(
            'Envelopes soft-deleted more than %d day(s) ago are eligible for object removal.',
            $policy->purgeGraceDays,
        ));

        $eligible = array_values(array_filter($planned, static fn (array $entry): bool => ! $entry['held']));
        $held = count($planned) - count($eligible);
        $objects = array_sum(array_map(static fn (array $entry): int => count($entry['objects']), $eligible));

        if ($held > 0) {
            $this->warn(sprintf(
                '%d soft-deleted envelope(s) are under legal hold and their bytes will not be removed.',
                $held,
            ));
        }

        $this->line(sprintf('%d envelope(s), %d object(s) eligible.', count($eligible), $objects));

        foreach ($eligible as $entry) {
            foreach ($entry['objects'] as $object) {
                $this->line(sprintf(
                    '  envelope %s: %s %s',
                    $entry['envelope'],
                    $object['kind'],
                    substr($object['sha256'], 0, 12),
                ));
            }
        }

        if ($this->option('dry-run')) {
            $this->comment('Dry run. Nothing was removed.');

            return self::SUCCESS;
        }

        if ($eligible === []) {
            return self::SUCCESS;
        }

        if (! $this->confirmToProceed('Purging removes the bytes of the agreements listed above permanently')) {
            return self::FAILURE;
        }

        $result = $purger->purge($planned, AuditActor::console('esign:retention:purge-blobs'));

        $this->info(sprintf(
            'Removed %d object(s) across %d envelope(s). %d were already gone, %d could not be removed, '
            .'%d envelope(s) were skipped for a legal hold.',
            $result['purged'],
            $result['envelopes'],
            $result['already_gone'],
            $result['failed'],
            $result['held_skipped'],
        ));

        if ($result['failed'] > 0) {
            $this->warn(
                'Some objects could not be removed. Their rows still name them, so re-running will try '
                .'again; nothing has been lost.'
            );
        }

        return self::SUCCESS;
    }
}
