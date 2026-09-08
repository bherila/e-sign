<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Health;

/**
 * Tri-state result of a single readiness probe. Ordered by severity so the
 * overall report can pick the worst status across every probe.
 */
enum HealthStatus: string
{
    case Ok = 'ok';
    case Warn = 'warn';
    case Fail = 'fail';

    public function severity(): int
    {
        return match ($this) {
            self::Ok => 0,
            self::Warn => 1,
            self::Fail => 2,
        };
    }

    /**
     * @param  HealthStatus[]  $statuses
     */
    public static function worst(array $statuses): self
    {
        $worst = self::Ok;

        foreach ($statuses as $status) {
            if ($status->severity() > $worst->severity()) {
                $worst = $status;
            }
        }

        return $worst;
    }
}
