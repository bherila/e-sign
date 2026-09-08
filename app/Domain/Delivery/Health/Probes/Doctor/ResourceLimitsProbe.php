<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Health\Probes\Doctor;

use App\Domain\Delivery\Health\HealthProbe;
use App\Domain\Delivery\Health\ProbeResult;

/**
 * Checks `memory_limit` and `max_execution_time` for the CLI SAPI this process is running
 * under, against the minimums `config('esign.cpanel')` states.
 *
 * Neither minimum is a value measured anywhere else in this repository — nothing in
 * docs/evidence/finalization.md sets a floor for sealing a large synthetic PDF, so 512 MiB and
 * 300 seconds are this diagnostic's own conservative proposal, documented as exactly that in
 * docs/operations/cpanel.md and overridable via `ESIGN_CPANEL_MIN_MEMORY_BYTES` /
 * `ESIGN_CPANEL_MIN_EXECUTION_SECONDS` once a deployment has real measurements to replace them
 * with.
 *
 * This only ever reads `php.ini`/CLI values via `ini_get()`; a cPanel account's web SAPI
 * (PHP-FPM/LSAPI) can and often does have a *different* php.ini, which this probe cannot see
 * any more than WebPhpVersionProbe can see the web runtime's version.
 */
final class ResourceLimitsProbe implements HealthProbe
{
    public function name(): string
    {
        return 'resource_limits';
    }

    public function check(): ProbeResult
    {
        $minMemoryBytes = (int) config('esign.cpanel.min_memory_bytes', 512 * 1024 * 1024);
        $minExecutionSeconds = (int) config('esign.cpanel.min_execution_seconds', 300);

        $memoryLimit = self::parseIniBytes((string) ini_get('memory_limit'));
        $maxExecutionTime = (int) ini_get('max_execution_time');

        $problems = [];

        // -1 means unlimited.
        if ($memoryLimit !== -1 && $memoryLimit < $minMemoryBytes) {
            $problems[] = sprintf(
                'memory_limit is %s, below the %s minimum',
                self::formatBytes($memoryLimit),
                self::formatBytes($minMemoryBytes),
            );
        }

        // 0 means unlimited (the common CLI default).
        if ($maxExecutionTime !== 0 && $maxExecutionTime < $minExecutionSeconds) {
            $problems[] = sprintf(
                'max_execution_time is %ds, below the %ds minimum',
                $maxExecutionTime,
                $minExecutionSeconds,
            );
        }

        if ($problems !== []) {
            return ProbeResult::fail($this->name(), ucfirst(implode('; ', $problems)).'.');
        }

        return ProbeResult::ok($this->name(), sprintf(
            'memory_limit=%s, max_execution_time=%s meet the configured minimums.',
            self::formatBytes($memoryLimit),
            $maxExecutionTime === 0 ? 'unlimited' : "{$maxExecutionTime}s",
        ));
    }

    /** Parses a php.ini memory value ("512M", "1G", "-1", a bare integer of bytes) into bytes. */
    private static function parseIniBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '' || $value === '-1') {
            return -1;
        }

        if (preg_match('/^(-?\d+)([KMG]?)$/i', $value, $matches) !== 1) {
            return 0;
        }

        $number = (int) $matches[1];
        $unit = strtoupper($matches[2]);

        return match ($unit) {
            'G' => $number * 1024 * 1024 * 1024,
            'M' => $number * 1024 * 1024,
            'K' => $number * 1024,
            default => $number,
        };
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes === -1) {
            return 'unlimited';
        }

        if ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024)).'M';
        }

        return "{$bytes}B";
    }
}
