<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Retention\Console;

use App\Domain\Evidence\Retention\BackupManifest;
use App\Domain\Evidence\Retention\Exceptions\RetentionRefused;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;

/**
 * Writes the JSON a restore drill is checked against.
 *
 * Run it immediately next to the database dump, in the same backup script, and back up the
 * file it writes along with everything else. A manifest taken an hour later describes a
 * different database than the dump does, and the comparison then produces failures that
 * mean nothing — which is how a check gets turned off.
 *
 * The file holds row counts and digest totals. It holds no document bytes, no addresses, and
 * no key material, so it is safe to keep next to the dump rather than under the separate
 * handling the seal key needs (docs/operations/backups.md).
 */
final class WriteBackupManifestCommand extends Command
{
    protected $signature = 'esign:backup:manifest
        {--path= : Where to write the manifest. Defaults to esign.retention.manifest_path.}';

    protected $description = 'Record row counts per table and artifact digest totals for a restore drill to check';

    public function handle(BackupManifest $manifests, Repository $config): int
    {
        $path = $this->resolvePath($config);
        $manifest = $manifests->build();

        try {
            $manifests->write($path, $manifest);
        } catch (RetentionRefused $refusal) {
            $this->error($refusal->getMessage());

            return self::FAILURE;
        }

        /** @var array<string, int> $tables */
        $tables = $manifest['tables'];
        /** @var array{count: int, bytes: int, digest_total: string} $artifacts */
        $artifacts = $manifest['artifacts'];

        $this->info('Wrote '.$path.'.');
        $this->line(sprintf(
            '%d table(s), %d row(s) in total, %d published artifact(s) totalling %d byte(s).',
            count($tables),
            array_sum($tables),
            $artifacts['count'],
            $artifacts['bytes'],
        ));
        $this->line('Artifact digest total: '.$artifacts['digest_total']);
        $this->comment(
            'Back this file up with the dump. `esign:restore:verify` compares a restored instance '
            .'against it, and a drill with nothing to compare against only proves the application boots.'
        );

        return self::SUCCESS;
    }

    private function resolvePath(Repository $config): string
    {
        $path = $this->option('path') === null
            ? (string) $config->get('esign.retention.manifest_path', 'storage/app/backups/manifest.json')
            : (string) $this->option('path');

        return str_starts_with($path, '/') ? $path : base_path($path);
    }
}
