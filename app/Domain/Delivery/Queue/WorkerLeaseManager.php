<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Queue;

use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * A database-backed mutual-exclusion lease over the `worker_leases` table, used by
 * `esign:queue:work-bounded` so an overlapping cron tick on a shared-hosting account never
 * starts a second `queue:work` process while one is already running (docs/HANDOFF.md section
 * 13). There is no persistent daemon and no process registry to ask "is a worker already
 * running?" — the lease row *is* the answer.
 *
 * ## Why a plain UPDATE ... WHERE, not SELECT ... FOR UPDATE
 *
 * Every operation here is a single atomic statement whose WHERE clause encodes the precondition,
 * rather than a read followed by a conditional write. That is what makes it race-safe across two
 * processes without a distributed lock: a database's own row-level locking on a single UPDATE (or
 * the unique index on `name` for the first INSERT) is the only synchronization primitive this
 * needs, and it behaves identically on SQLite (globally serialized writers), MySQL 8, and MariaDB
 * (docs/adr/0002-supported-databases.md) without depending on transaction isolation level.
 *
 * ## Why a heartbeat can fail without the worker being told to stop mid-job
 *
 * A heartbeat only runs between jobs (`Illuminate\Queue\Events\Looping`, see
 * App\Domain\Delivery\Queue\Console\WorkBoundedCommand). If a single job runs long enough that no
 * heartbeat lands before the lease's TTL elapses, another cron tick is entitled to treat this
 * lease as abandoned and take it over — that is the intended behaviour, not a bug, which is why
 * `lease_ttl` must comfortably exceed `max_time` plus the longest job's own timeout
 * (config/esign.php `queue` section). When that happens this manager reports it truthfully
 * (heartbeat()/release() both return false) rather than silently succeeding against a row that
 * now belongs to someone else.
 */
final class WorkerLeaseManager
{
    private const TABLE = 'worker_leases';

    /**
     * Acquire the named lease for $holderToken. Succeeds either because no row exists yet, or
     * because the existing row's lease has expired. Returns false when a live (unexpired) lease
     * is already held by someone else — the caller's signal to refuse to start a worker.
     */
    public function acquire(string $name, string $holderToken, CarbonImmutable $now, int $ttlSeconds): bool
    {
        $expiresAt = $now->addSeconds($ttlSeconds);

        try {
            DB::table(self::TABLE)->insert([
                'name' => $name,
                'holder_token' => $holderToken,
                'acquired_at' => $now,
                'expires_at' => $expiresAt,
                'heartbeat_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return true;
        } catch (UniqueConstraintViolationException) {
            // A row for this name already exists. It is only takeable if it is stale.
        }

        $taken = DB::table(self::TABLE)
            ->where('name', $name)
            ->where('expires_at', '<', $now)
            ->update([
                'holder_token' => $holderToken,
                'acquired_at' => $now,
                'expires_at' => $expiresAt,
                'heartbeat_at' => $now,
                'updated_at' => $now,
            ]);

        return $taken === 1;
    }

    /**
     * Extend the lease and record a heartbeat. Returns false when $holderToken no longer matches
     * the row's current holder — i.e. this lease was taken over by someone else while this
     * process was still running, which means this process must stop working the queue.
     */
    public function heartbeat(string $name, string $holderToken, CarbonImmutable $now, int $ttlSeconds): bool
    {
        $updated = DB::table(self::TABLE)
            ->where('name', $name)
            ->where('holder_token', $holderToken)
            ->update([
                'expires_at' => $now->addSeconds($ttlSeconds),
                'heartbeat_at' => $now,
                'updated_at' => $now,
            ]);

        return $updated === 1;
    }

    /**
     * Release the lease. A no-op (and returns false) when $holderToken no longer matches the
     * row's current holder, so a process that already lost its lease to a stale takeover can
     * never delete the new holder's row out from under it.
     */
    public function release(string $name, string $holderToken): bool
    {
        $deleted = DB::table(self::TABLE)
            ->where('name', $name)
            ->where('holder_token', $holderToken)
            ->delete();

        return $deleted === 1;
    }
}
