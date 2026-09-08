<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Retention\Console;

use App\Domain\Evidence\Retention\ArtifactIntegrityVerifier;
use App\Domain\Identity\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Re-reads every published artifact, recomputes its SHA-256, and re-validates the executed
 * PDFs.
 *
 * Output is shaped for a scheduled job: one line per problem, a summary, and a non-zero exit
 * status the moment anything did not match. `routes/console.php` runs it weekly, and the
 * `artifact_integrity` readiness probe reads the row it records rather than doing the work
 * itself — re-hashing every artifact is minutes of I/O and does not belong on an HTTP
 * endpoint.
 *
 * A non-zero exit on a mismatch is the whole contract. A verification that reported a
 * corrupted artifact and exited 0 would be worse than not running: cron would swallow it,
 * and the digest that no longer matches is the one thing this application promises.
 */
final class VerifyArtifactsCommand extends Command
{
    protected $signature = 'esign:artifacts:verify
        {--workspace= : Restrict to one workspace, by public id}
        {--since= : Only artifacts published at or after this date/time (e.g. 2026-01-01, -30 days)}';

    protected $description = 'Re-read every published artifact and check its digest and seal still match the row';

    public function handle(ArtifactIntegrityVerifier $verifier): int
    {
        $workspace = $this->option('workspace') === null ? null : (string) $this->option('workspace');

        if ($workspace !== null && Workspace::query()->where('public_id', $workspace)->doesntExist()) {
            $this->error('No workspace with public id '.$workspace.'.');

            return self::INVALID;
        }

        $since = null;

        if ($this->option('since') !== null) {
            try {
                $since = CarbonImmutable::parse((string) $this->option('since'));
            } catch (Throwable) {
                $this->error('--since could not be read as a date or time.');

                return self::INVALID;
            }
        }

        $run = $verifier->verify($workspace, $since);

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
            '%d artifact(s) checked: %d digest mismatch(es), %d missing or unreadable object(s), '
            .'%d invalid seal(s).',
            $run->artifacts_checked,
            $run->digest_mismatches,
            $run->missing_objects,
            $run->invalid_signatures,
        ));

        if ($run->problemCount() > count($run->findings ?? [])) {
            $this->warn(sprintf(
                'Only the first %d finding(s) were recorded on the run row; the counts above are complete.',
                ArtifactIntegrityVerifier::MAX_FINDINGS,
            ));
        }

        if ($run->problemCount() > 0) {
            $this->error('Artifact integrity verification failed. Run '.$run->public_id.'.');

            return self::FAILURE;
        }

        $this->info('Every published artifact still matches its recorded digest. Run '.$run->public_id.'.');

        return self::SUCCESS;
    }
}
