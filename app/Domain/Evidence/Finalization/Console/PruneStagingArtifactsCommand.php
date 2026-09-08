<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Finalization\Console;

use App\Domain\Evidence\Finalization\Artifacts\ArtifactStorageKey;
use App\Domain\Evidence\Finalization\Artifacts\ArtifactStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Removes artifact objects that no `artifacts` row references and that are old enough to be
 * certainly abandoned.
 *
 * ## What "staging" means here
 *
 * There is no separate staging prefix, and that is deliberate. An artifact key is
 * content-addressed, so the object a finalization uploads in step 2 already sits at the key
 * it will be published under; what makes it *staging* is that no row points at it yet. A
 * finalization that crashes after the upload therefore leaves an unreferenced object at a
 * final-shaped key, and the retry publishes that exact object rather than writing a second
 * copy somewhere else. "Unreferenced" is a fact this command can check, whereas "in a staging
 * folder" would be a convention it would have to trust.
 *
 * ## Why it is this conservative
 *
 * docs/HANDOFF.md section 12: "Clean up only provably unreferenced staging objects under a
 * conservative policy; never garbage-collect completed evidence by prefix age." Four rules
 * follow, and each one exists because its absence deletes evidence:
 *
 *  1. **Set membership, not age.** An object is a candidate only when no `artifacts` row names
 *     its disk and path. Age is a second filter applied to candidates, never the test itself.
 *  2. **Queried through the query builder**, so no model scope can hide a row and condemn the
 *     bytes it protects (docs/BLOB_STORAGE.md, "soft-deleted rows still count").
 *  3. **A minimum age.** An object exists before the row that references it — that is the
 *     whole shape of the staged publication — so anything younger than the window is left
 *     alone even if it is unreferenced right now. The default is seven days, which is far
 *     longer than any finalization takes and is meant to be.
 *  4. **Dry run by default.** `--apply` is required to delete anything. The safe mode is the
 *     one you get when you forget (docs/BLOB_STORAGE.md rule 4).
 *
 * The prefix is not a caller's to choose: the command enumerates the one root artifact keys
 * are built under. A `--prefix` option is one empty string away from sweeping a whole bucket.
 */
final class PruneStagingArtifactsCommand extends Command
{
    protected $signature = 'esign:artifacts:prune-staging
        {--older-than=7d : Minimum age of an unreferenced object before it may be removed (e.g. 7d, 48h)}
        {--apply : Actually delete. Without this the command only reports what it would do}';

    protected $description = 'Remove artifact objects that no published artifact row references';

    public function handle(ArtifactStore $store): int
    {
        $seconds = $this->ageInSeconds((string) $this->option('older-than'));

        if ($seconds === null) {
            $this->error('--older-than must look like 7d, 48h, 90m, or a plain number of seconds.');

            return self::INVALID;
        }

        $disk = (string) config('esign.documents.disk');
        $objects = $store->listWithTimestamps($disk, ArtifactStorageKey::ROOT);

        if ($objects === []) {
            $this->info('No artifact objects are stored under '.ArtifactStorageKey::ROOT.'/.');

            return self::SUCCESS;
        }

        // Read through the query builder rather than Eloquent: a global scope that hid a row
        // would turn a referenced object into an unreferenced-looking one.
        $referenced = DB::table('artifacts')
            ->where('disk', $disk)
            ->pluck('path')
            ->all();

        $referenced = array_fill_keys(array_map('strval', $referenced), true);

        $cutoff = time() - $seconds;
        $candidates = [];
        $tooYoung = 0;

        foreach ($objects as $path => $modifiedAt) {
            if (isset($referenced[$path])) {
                continue;
            }

            if ($modifiedAt > $cutoff) {
                $tooYoung++;

                continue;
            }

            $candidates[] = $path;
        }

        $this->line(sprintf(
            '%d object(s) stored, %d referenced by an artifact row, %d unreferenced but newer than the '
            .'age threshold, %d removable.',
            count($objects),
            count($objects) - count($candidates) - $tooYoung,
            $tooYoung,
            count($candidates),
        ));

        if ($candidates === []) {
            return self::SUCCESS;
        }

        if (! $this->option('apply')) {
            foreach ($candidates as $path) {
                $this->line('would remove '.$path);
            }

            $this->comment('Dry run. Re-run with --apply to remove these objects.');

            return self::SUCCESS;
        }

        foreach ($candidates as $path) {
            $store->delete($disk, $path);
            $this->line('removed '.$path);
        }

        $this->info(count($candidates).' unreferenced object(s) removed.');

        return self::SUCCESS;
    }

    /** Accepts `7d`, `48h`, `90m`, `600s`, or a plain integer of seconds. Null when unreadable. */
    private function ageInSeconds(string $option): ?int
    {
        if (preg_match('/^(\d+)([dhms]?)$/i', trim($option), $matches) !== 1) {
            return null;
        }

        $value = (int) $matches[1];

        return match (strtolower($matches[2])) {
            'd' => $value * 86_400,
            'h' => $value * 3_600,
            'm' => $value * 60,
            default => $value,
        };
    }
}
