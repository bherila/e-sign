<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per named lease. `esign:queue:work-bounded` (App\Domain\Delivery\Queue\
 * WorkerLeaseManager) is the only reader/writer: it refuses to start a second worker
 * while a live lease exists, so overlapping cron ticks on a shared-hosting account
 * never run two `queue:work` processes at once (docs/HANDOFF.md section 13).
 *
 * `dateTime()` rather than `timestamp()` for the NOT NULL columns, per AGENTS.md
 * Database safety rule 6: MariaDB 10.6 gives the first NOT NULL TIMESTAMP column an
 * implicit `ON UPDATE CURRENT_TIMESTAMP`, which would silently rewrite acquired_at on
 * every heartbeat's UPDATE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_leases', function (Blueprint $table): void {
            $table->id();

            // Which bounded-worker profile this lease guards, e.g. 'esign-queue-worker'.
            // A deployment that runs more than one profile (different --queue selections)
            // names them distinctly via --lease-name; unique so two acquire attempts for
            // the same name can never both succeed.
            $table->string('name', 128)->unique();

            // Opaque token identifying the current holder (a UUID minted per invocation,
            // never derived from anything guessable). A heartbeat or release only ever
            // succeeds when it matches the row's current token, so a worker that lost its
            // lease to a stale takeover can never heartbeat or release someone else's.
            $table->string('holder_token', 64);

            $table->dateTime('acquired_at');

            // Refreshed on every heartbeat, sliding the lease forward. A lease is live
            // while expires_at is in the future and stale once it is not: stale is what
            // makes an abandoned lease (crashed process, killed cron job) takeable again
            // without any manual intervention.
            $table->dateTime('expires_at');

            $table->dateTime('heartbeat_at')->nullable();

            $table->timestamps();

            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_leases');
    }
};
