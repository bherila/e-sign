<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Health\Probes\Doctor;

use App\Domain\Delivery\Health\HealthProbe;
use App\Domain\Delivery\Health\ProbeResult;

/**
 * Confirms `.env` is present and `APP_KEY` resolves to a non-empty value, without ever printing
 * either. An install that copied `.env.example` and forgot `php artisan key:generate` boots
 * successfully and fails confusingly later (session encryption, encrypted columns) rather than
 * at the point this probe catches it.
 */
final class EnvironmentProbe implements HealthProbe
{
    public function name(): string
    {
        return 'environment';
    }

    public function check(): ProbeResult
    {
        $keySet = filled(config('app.key'));
        $envExists = is_file(base_path('.env'));

        if (! $keySet) {
            return ProbeResult::fail($this->name(), 'APP_KEY is not set. Run php artisan key:generate.');
        }

        if (! $envExists) {
            return ProbeResult::warn(
                $this->name(),
                'No .env file found; APP_KEY resolved from the process environment or a cached config instead.',
            );
        }

        return ProbeResult::ok($this->name(), '.env is present and APP_KEY is set.');
    }
}
