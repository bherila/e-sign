<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Retention\Console;

use App\Domain\Evidence\Retention\ArtifactIntegrityVerifier;
use App\Domain\Evidence\Retention\BackupManifest;
use App\Domain\Evidence\Retention\Exceptions\RetentionRefused;
use App\Domain\Evidence\Retention\RestoreDrill;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;

/**
 * Checks that a restored copy is actually the instance that was backed up.
 *
 * ## It refuses to run anywhere else
 *
 * `ESIGN_RESTORE_DRILL=1` and `APP_ENV != production`, both required, checked before it
 * touches anything. The first is what also stops the restored copy sending mail and
 * webhooks to the addresses and endpoints it inherited; the second is what stops the drill
 * being pointed at the instance serving traffic by a mistyped host. Neither is sufficient
 * alone — a variable can be set anywhere, and every staging instance satisfies the second —
 * so the command asks for both. See {@see RestoreDrill}.
 *
 * ## The two halves of the check
 *
 * **The manifest** says the database restored completely: a row count per table, compared
 * against what was there when the dump was taken, plus a digest total over the published
 * artifacts. It catches the dump that was truncated, the table that was excluded, and the
 * import that ran out of disk at 90%.
 *
 * **The integrity verification** says the object store restored too: every published
 * artifact is re-read through the storage adapter, re-hashed, and its seal re-validated. A
 * database that agrees with itself while its documents are missing is the failure mode a
 * count comparison cannot see, and it is the likelier of the two — the database and the
 * bucket are backed up by different mechanisms on different schedules.
 *
 * Both must pass. The exit status is non-zero if either does not, so the drill can be a step
 * in a script rather than something somebody reads.
 */
final class VerifyRestoreCommand extends Command
{
    protected $signature = 'esign:restore:verify
        {--manifest= : The manifest written by esign:backup:manifest. Defaults to esign.retention.manifest_path.}
        {--skip-artifacts : Compare the manifest only, without re-reading the artifact objects}';

    protected $description = 'Assert a restored copy matches its backup manifest and its artifacts still verify';

    public function handle(
        RestoreDrill $drill,
        BackupManifest $manifests,
        ArtifactIntegrityVerifier $verifier,
        Repository $config,
    ): int {
        try {
            $drill->assertDrilling();
        } catch (RetentionRefused $refusal) {
            $this->error($refusal->getMessage());

            return self::FAILURE;
        }

        $path = $this->resolvePath($config);

        try {
            $manifest = $manifests->read($path);
        } catch (RetentionRefused $refusal) {
            $this->error($refusal->getMessage());

            return self::FAILURE;
        }

        $this->info('Restore drill against '.$path.', taken '.(string) ($manifest['generated_at'] ?? 'at an unrecorded time').'.');
        $this->comment('Mail and webhook delivery are refusing to send while ESIGN_RESTORE_DRILL is set.');
        $this->newLine();

        $differences = $manifests->differencesFrom($manifest);

        foreach ($differences as $difference) {
            $this->error($difference);
        }

        if ($differences === []) {
            $this->info('Every non-volatile table and the artifact digest total match the manifest.');
        }

        if ($this->option('skip-artifacts')) {
            $this->warn(
                'Skipped re-reading the artifact objects. The database matches the manifest; whether the '
                .'documents behind it survived has not been checked.'
            );

            return $differences === [] ? self::SUCCESS : self::FAILURE;
        }

        $run = $verifier->verify();

        foreach ($run->findings ?? [] as $finding) {
            $this->error(sprintf(
                '%s artifact %s of envelope %s: %s',
                (string) ($finding['kind'] ?? 'unknown'),
                (string) ($finding['artifact'] ?? 'unknown'),
                (string) ($finding['envelope'] ?? 'unknown'),
                (string) ($finding['detail'] ?? (string) ($finding['problem'] ?? 'unspecified')),
            ));
        }

        $this->line(sprintf(
            '%d artifact(s) re-read: %d digest mismatch(es), %d missing or unreadable object(s), '
            .'%d invalid seal(s).',
            $run->artifacts_checked,
            $run->digest_mismatches,
            $run->missing_objects,
            $run->invalid_signatures,
        ));

        if ($differences !== [] || $run->problemCount() > 0) {
            $this->error('The restore is not verified. This backup could not be used to serve the agreements it holds.');

            return self::FAILURE;
        }

        $this->info('Restore verified: the database matches the manifest and every published artifact reads back intact.');

        return self::SUCCESS;
    }

    private function resolvePath(Repository $config): string
    {
        $path = $this->option('manifest') === null
            ? (string) $config->get('esign.retention.manifest_path', 'storage/app/backups/manifest.json')
            : (string) $this->option('manifest');

        return str_starts_with($path, '/') ? $path : base_path($path);
    }
}
