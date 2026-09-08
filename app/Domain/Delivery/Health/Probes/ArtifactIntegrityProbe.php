<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Health\Probes;

use App\Domain\Delivery\Health\HealthProbe;
use App\Domain\Delivery\Health\ProbeResult;
use App\Domain\Evidence\Retention\ArtifactVerificationRun;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Whether the published evidence has recently been re-read and still matched.
 *
 * The probe does not verify anything itself. Re-hashing every artifact is minutes of I/O,
 * and a readiness endpoint that did it would either time out or become a way to make the
 * instance unavailable by requesting it repeatedly. `esign:artifacts:verify` does the work
 * on the weekly schedule in `routes/console.php` and records the outcome; this reads the
 * last record.
 *
 * That split creates a second failure mode, and the probe treats it as the more important
 * one: a verification that **stopped running** is worse than one that ran and failed,
 * because a failure is visible and a silence is not. So an old result warns even when it
 * passed.
 *
 * | Condition | Status |
 * |---|---|
 * | Last completed run passed, within `esign.retention.verification_warn_days` | `ok` |
 * | Last completed run passed but is older than that | `warn` |
 * | No verification has ever completed | `warn` |
 * | Last completed run found any mismatch, missing object, or invalid seal | `fail` |
 *
 * A never-run verification warns rather than fails, because a freshly provisioned instance
 * has no artifacts and no schedule history, and failing readiness there would make a
 * correct deployment look broken on its first day. It stops warning the first time the
 * scheduled command runs.
 *
 * The message carries counts and an age, never an envelope title, a workspace name, or a
 * storage key — the same rule every probe in this directory follows.
 */
final class ArtifactIntegrityProbe implements HealthProbe
{
    public function name(): string
    {
        return 'artifact_integrity';
    }

    public function check(): ProbeResult
    {
        try {
            $run = ArtifactVerificationRun::lastCompleted();
        } catch (Throwable) {
            return ProbeResult::fail($this->name(), 'The artifact verification history could not be read.');
        }

        if ($run === null) {
            return ProbeResult::warn(
                $this->name(),
                'No artifact integrity verification has completed yet. It runs weekly on the scheduler.',
            );
        }

        $problems = $run->problemCount();

        if ($problems > 0 || $run->passed === false) {
            return ProbeResult::fail($this->name(), sprintf(
                'The last artifact verification found %d problem(s) across %d artifact(s): %d digest '
                .'mismatch(es), %d missing object(s), %d invalid seal(s).',
                $problems,
                $run->artifacts_checked,
                $run->digest_mismatches,
                $run->missing_objects,
                $run->invalid_signatures,
            ));
        }

        $finishedAt = $run->finished_at ?? $run->started_at;
        // Epoch arithmetic rather than a Carbon diff helper, for the same reason
        // SchedulerHeartbeatProbe does it: the sign and the rounding of a diff helper are a
        // library detail, and a probe that reported a negative age on a clock skew would be
        // reporting nonsense.
        $ageSeconds = max(0, CarbonImmutable::now()->getTimestamp() - $finishedAt->getTimestamp());
        $ageDays = intdiv($ageSeconds, 86_400);
        $warnAfter = max(1, (int) config('esign.retention.verification_warn_days', 8));

        if ($ageDays >= $warnAfter) {
            return ProbeResult::warn($this->name(), sprintf(
                'The last artifact verification passed but ran %d day(s) ago; the weekly job may not be running.',
                $ageDays,
            ));
        }

        return ProbeResult::ok($this->name(), sprintf(
            '%d artifact(s) verified %d day(s) ago with no mismatches.',
            $run->artifacts_checked,
            $ageDays,
        ));
    }
}
