<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Retention;

use App\Domain\Evidence\Retention\Exceptions\RetentionRefused;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use JsonException;

/**
 * A count of everything, taken at backup time, so a restore can be checked against it.
 *
 * ## Why a manifest and not just "the restore worked"
 *
 * Because a restore that silently drops rows looks exactly like a restore that worked. A
 * dump taken while a transaction was open, a table excluded by a stale `--ignore-table`, a
 * disk that filled at 90% of the way through the import — each produces a database that
 * connects, migrates, and serves pages. The only way to notice is to have written down what
 * was there and to compare.
 *
 * So `esign:backup:manifest` runs next to the dump and records a row count per table plus a
 * digest total over the published artifacts, and `esign:restore:verify` reads it back in the
 * throwaway environment and compares. Neither is a substitute for the other: the counts
 * catch a truncated database, and the artifact digest total catches a database that
 * restored perfectly alongside an object store that did not.
 *
 * ## The artifact digest total
 *
 * Not a count of files. It is the SHA-256 of the sorted `kind:sha256` lines of every
 * published artifact — a single value that changes if any artifact is added, removed, or
 * has a different digest. Comparing one string is what makes the check usable in a runbook;
 * comparing a hundred thousand digests by hand is what makes a check get skipped.
 *
 * It is computed from the rows, not from the objects. Whether the bytes behind those
 * digests survived is `esign:artifacts:verify`'s question, and `esign:restore:verify` runs
 * that too — the manifest tells you the database agrees with itself, the verification tells
 * you the storage agrees with the database.
 *
 * ## Volatile tables
 *
 * Sessions, cache, and the queue tables are counted and then explicitly excluded from the
 * comparison. A restored copy legitimately has a different number of queued jobs and cached
 * rows than the instance the dump came from — a worker drained some, the cache expired —
 * and comparing them would produce a failure on every drill, which is the fastest way to
 * teach an operator to ignore the output.
 */
final readonly class BackupManifest
{
    /** Bumped when the shape below changes in a way a reader has to know about. */
    public const SCHEMA_VERSION = 1;

    /**
     * Tables whose counts are recorded but never compared. See the class docblock.
     *
     * @var list<string>
     */
    public const VOLATILE_TABLES = [
        'cache',
        'cache_locks',
        'sessions',
        'jobs',
        'job_batches',
        'failed_jobs',
        'password_reset_tokens',
        'api_idempotency_keys',
        'artifact_verification_runs',
    ];

    public function __construct(private ConnectionInterface $db) {}

    /**
     * @return array<string, mixed>
     */
    public function build(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $tables = [];

        foreach ($this->tableNames() as $table) {
            $tables[$table] = $this->db->table($table)->count();
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => $now->toIso8601String(),
            'app_env' => (string) config('app.env'),
            'database_driver' => $this->db->getDriverName(),
            'volatile_tables' => self::VOLATILE_TABLES,
            'tables' => $tables,
            'artifacts' => $this->artifactTotals(),
        ];
    }

    /**
     * @return array{count: int, bytes: int, digest_total: string}
     */
    public function artifactTotals(): array
    {
        $rows = $this->db->table('artifacts')
            ->whereNotNull('published_at')
            ->orderBy('sha256')
            ->orderBy('kind')
            ->get(['kind', 'sha256', 'bytes']);

        $lines = [];
        $bytes = 0;

        foreach ($rows as $row) {
            $lines[] = $row->kind.':'.$row->sha256;
            $bytes += (int) $row->bytes;
        }

        // Sorted before hashing so the total does not depend on the order the engine
        // happened to return rows in; the ORDER BY above is belt and braces on engines
        // whose collation orders hex differently.
        sort($lines, SORT_STRING);

        return [
            'count' => count($lines),
            'bytes' => $bytes,
            'digest_total' => hash('sha256', implode("\n", $lines)),
        ];
    }

    /**
     * Compare a manifest against the database this process is connected to.
     *
     * @param  array<string, mixed>  $manifest
     * @return list<string> One human sentence per discrepancy. Empty means it matched.
     */
    public function differencesFrom(array $manifest): array
    {
        $problems = [];

        /** @var array<string, mixed> $expectedTables */
        $expectedTables = is_array($manifest['tables'] ?? null) ? $manifest['tables'] : [];

        /** @var list<string> $volatile */
        $volatile = is_array($manifest['volatile_tables'] ?? null)
            ? array_map('strval', $manifest['volatile_tables'])
            : self::VOLATILE_TABLES;

        $present = $this->tableNames();

        foreach ($expectedTables as $table => $expected) {
            $table = (string) $table;

            if (in_array($table, $volatile, true)) {
                continue;
            }

            if (! in_array($table, $present, true)) {
                $problems[] = sprintf('Table %s is in the manifest but not in the restored database.', $table);

                continue;
            }

            $actual = $this->db->table($table)->count();

            if ($actual !== (int) $expected) {
                $problems[] = sprintf(
                    'Table %s holds %d row(s); the manifest recorded %d.',
                    $table,
                    $actual,
                    (int) $expected,
                );
            }
        }

        /** @var array<string, mixed> $expectedArtifacts */
        $expectedArtifacts = is_array($manifest['artifacts'] ?? null) ? $manifest['artifacts'] : [];
        $actualArtifacts = $this->artifactTotals();

        if (($expectedArtifacts['count'] ?? null) !== null
            && (int) $expectedArtifacts['count'] !== $actualArtifacts['count']) {
            $problems[] = sprintf(
                'The restored database has %d published artifact(s); the manifest recorded %d.',
                $actualArtifacts['count'],
                (int) $expectedArtifacts['count'],
            );
        }

        if (($expectedArtifacts['digest_total'] ?? null) !== null
            && ! hash_equals((string) $expectedArtifacts['digest_total'], $actualArtifacts['digest_total'])) {
            $problems[] = 'The artifact digest total does not match the manifest: the set of published '
                .'artifacts, or one of their digests, is not what was backed up.';
        }

        return $problems;
    }

    /**
     * @param  array<string, mixed>  $manifest
     *
     * @throws RetentionRefused When the directory cannot be created or the file not written.
     */
    public function write(string $path, array $manifest): void
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0o750, true) && ! is_dir($directory)) {
            throw new RetentionRefused(sprintf('Could not create the manifest directory %s.', $directory));
        }

        try {
            $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RetentionRefused('The manifest could not be encoded: '.$exception->getMessage());
        }

        if (@file_put_contents($path, $json."\n") === false) {
            throw new RetentionRefused(sprintf('Could not write the manifest to %s.', $path));
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RetentionRefused When the file is absent or is not a manifest.
     */
    public function read(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RetentionRefused(sprintf(
                'No readable backup manifest at %s. Run `esign:backup:manifest` next to the database '
                .'dump; a restore drill with nothing to compare against only proves the application boots.',
                $path,
            ));
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RetentionRefused('The manifest at '.$path.' is not valid JSON: '.$exception->getMessage());
        }

        if (! is_array($decoded) || ! isset($decoded['schema_version'], $decoded['tables'])) {
            throw new RetentionRefused('The file at '.$path.' is not a backup manifest.');
        }

        if ((int) $decoded['schema_version'] !== self::SCHEMA_VERSION) {
            throw new RetentionRefused(sprintf(
                'The manifest at %s is schema version %d; this release writes and reads version %d.',
                $path,
                (int) $decoded['schema_version'],
                self::SCHEMA_VERSION,
            ));
        }

        return $decoded;
    }

    /**
     * Every table in the connected database, sorted.
     *
     * Derived from the live schema rather than from a hardcoded list, for the reason
     * docs/BLOB_STORAGE.md gives about the blob pruner's reference map: a list maintained by
     * hand silently stops covering the table somebody added last month, and a manifest that
     * omits a table cannot notice that the table restored empty.
     *
     * @return list<string>
     */
    public function tableNames(): array
    {
        $names = array_map(
            static fn (array $table): string => (string) $table['name'],
            $this->db->getSchemaBuilder()->getTables(),
        );

        sort($names, SORT_STRING);

        return array_values($names);
    }
}
