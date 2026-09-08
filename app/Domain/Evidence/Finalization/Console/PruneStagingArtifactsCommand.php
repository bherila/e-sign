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

        // Rule 3 of this class's contract — "a minimum age" — used to be documentation only:
        // `--older-than=0` set the cutoff to now, so an object written this second was old
        // enough to delete, and `publish()` does not re-check that its uploaded objects still
        // exist (docs/security/review-2026-09.md finding B-2).
        if ($seconds < self::MINIMUM_AGE_SECONDS) {
            $this->error(sprintf(
                '--older-than must be at least %ds. A shorter window can delete an object between '
                .'its upload and the transaction that publishes it.',
                self::MINIMUM_AGE_SECONDS,
            ));

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
        //
        // And read *every* artifact row, not only the ones whose `disk` matches the current
        // configuration. `artifacts.disk` is frozen at publication; renaming
        // `ESIGN_DOCUMENTS_DISK` — the same-bytes-new-name migration docs/BLOB_STORAGE.md
        // contemplates — left every historical row on the old name while this enumerated the
        // new one, so the referenced set came back empty and every published executed PDF,
        // completion report, and evidence document looked unreferenced at once, legal hold
        // included (docs/security/review-2026-09.md finding B-1). A path is a
        // content-addressed key under one root; treating it as referenced whichever disk name
        // a row records is the safe direction, because the failure of a too-wide set is that
        // an orphan survives another day.
        $referenced = DB::table('artifacts')->pluck('path')->all();

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

        // The abort docs/BLOB_STORAGE.md asks for. When a column stops being consulted — or a
        // prefix is enumerated that nothing has published to — everything it protected looks
        // unreferenced at once, and the ratio is what says so. A genuine staging orphan is a
        // rare leftover from a crashed finalization, never most of the bucket.
        $removableShare = count($candidates) / count($objects);

        if ($removableShare > self::IMPLAUSIBLE_SHARE) {
            $this->error(sprintf(
                '%d of %d objects (%d%%) look unreferenced. That is implausible for staging leftovers and '
                .'is what a disk rename or an empty artifacts table looks like. Refusing; nothing was '
                .'removed. Check that artifacts rows exist for this storage before re-running.',
                count($candidates),
                count($objects),
                (int) round($removableShare * 100),
            ));

            return self::FAILURE;
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

    /**
     * Floor on `--older-than`.
     *
     * One hour is far longer than the gap between an upload and the transaction that
     * publishes it — a B-T seal's TSA round trip is seconds — and short enough that a genuine
     * orphan is reclaimed the same day.
     */
    public const MINIMUM_AGE_SECONDS = 3_600;

    /**
     * Refuse when more than this share of the enumerated objects look unreferenced.
     *
     * Not a tuning knob. It is the difference between "a finalization crashed last week" and
     * "the set this command uses to decide what is safe has stopped working".
     */
    public const IMPLAUSIBLE_SHARE = 0.5;

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
