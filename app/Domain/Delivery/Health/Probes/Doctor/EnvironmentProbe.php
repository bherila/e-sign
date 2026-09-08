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

        // Nothing else refuses this combination, and `.env.example` ships APP_DEBUG=true, so
        // an operator who sets APP_ENV=production and forgets the other line gets stack
        // traces, environment dumps, and SQL rendered to whoever caused the error — with no
        // signal anywhere (docs/security/review-2026-09.md finding X-7). A failing readiness
        // probe is the signal, and it fails rather than warns because for a product holding
        // seal keys and signer evidence this is not a preference.
        if (app()->environment('production') && (bool) config('app.debug')) {
            return ProbeResult::fail(
                $this->name(),
                'APP_DEBUG is true with APP_ENV=production. Set APP_DEBUG=false: debug output renders '
                .'stack traces, configuration, and SQL to whoever triggered the error.',
            );
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
