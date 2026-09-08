<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Health\Probes;

use App\Domain\Delivery\Health\HealthProbe;
use App\Domain\Delivery\Health\HealthStatus;
use App\Domain\Delivery\Health\ProbeResult;
use App\Domain\Delivery\Mail\MailState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * How long the oldest unsent message has been waiting, and how many were given up on in the
 * last 24 hours.
 *
 * Distinct from the `mail` probe, which only checks that a delivering transport is
 * configured. Configuration can be perfect while nothing is being sent — no queue worker on
 * the `mail` queue is the usual cause, and it produces exactly the symptom this probe
 * exists to catch: invitations that sit at `queued` while the sender watches a dashboard
 * that says everything is fine.
 *
 * It measures `queued` only. A message at `sent_to_provider` is out of this application's
 * hands, and counting it as backlog would make a working deployment look broken. A `failed`
 * count above zero warns rather than fails, because one address that no longer exists is
 * not an outage; a large number of them is.
 *
 * Reads through the query builder rather than Eloquent, like the other probes: readiness has
 * to answer while the application is degraded, and that is not the moment to boot model
 * events and casts.
 */
final class MailBacklogProbe implements HealthProbe
{
    public function name(): string
    {
        return 'mail_backlog';
    }

    public function check(): ProbeResult
    {
        try {
            $oldestQueuedAt = DB::table('outbound_mails')
                ->where('state', MailState::Queued->value)
                ->min('created_at');

            $failedLast24h = DB::table('outbound_mails')
                ->where('state', MailState::Failed->value)
                ->where('state_changed_at', '>=', CarbonImmutable::now()->subDay())
                ->count();
        } catch (Throwable) {
            // Never the table name, the connection, or the driver: the readiness body is
            // returned to a caller with an operator token, not necessarily to an operator.
            return ProbeResult::fail($this->name(), 'The mail outbox is unavailable.');
        }

        $backlogStatus = HealthStatus::Ok;
        $ageSeconds = null;

        if ($oldestQueuedAt !== null) {
            $ageSeconds = max(0, CarbonImmutable::now()->getTimestamp() - $this->timestampOf($oldestQueuedAt));

            $warnAt = (int) config('esign.mail.backlog_warn_seconds', 300);
            $failAt = (int) config('esign.mail.backlog_fail_seconds', 1800);

            $backlogStatus = match (true) {
                $ageSeconds >= $failAt => HealthStatus::Fail,
                $ageSeconds >= $warnAt => HealthStatus::Warn,
                default => HealthStatus::Ok,
            };
        }

        $failedWarnAt = (int) config('esign.mail.failed_warn_count', 1);
        $failedFailAt = (int) config('esign.mail.failed_fail_count', 25);

        $failedStatus = match (true) {
            $failedLast24h >= $failedFailAt => HealthStatus::Fail,
            $failedLast24h >= $failedWarnAt => HealthStatus::Warn,
            default => HealthStatus::Ok,
        };

        $backlogMessage = $oldestQueuedAt === null
            ? 'No queued mail.'
            : "Oldest queued message is {$ageSeconds}s old.";

        return new ProbeResult(
            $this->name(),
            HealthStatus::worst([$backlogStatus, $failedStatus]),
            "{$backlogMessage} {$failedLast24h} message(s) failed in the last 24h.",
        );
    }

    /**
     * `min()` on a timestamp column comes back as a string on MySQL and MariaDB and as
     * whatever was written on SQLite, so the value is parsed rather than cast.
     */
    private function timestampOf(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        try {
            return CarbonImmutable::parse((string) $value)->getTimestamp();
        } catch (Throwable) {
            // Unparseable means the age cannot be computed; treating it as "now" reports no
            // backlog rather than inventing one.
            return CarbonImmutable::now()->getTimestamp();
        }
    }
}
