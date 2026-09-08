<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Health\Probes\Doctor;

use App\Domain\Delivery\Health\HealthProbe;
use App\Domain\Delivery\Health\ProbeResult;

/**
 * `storage/` and `bootstrap/cache` must be writable by the PHP-FPM/LSAPI user the web vhost
 * runs as. A cPanel deploy that rsyncs a fresh tree with the wrong ownership fails on the very
 * first request (logs, sessions, view/route/config cache) with no other symptom. Paths are
 * never printed past the fixed, non-secret directory name.
 */
final class WritablePathsProbe implements HealthProbe
{
    public function name(): string
    {
        return 'writable_paths';
    }

    public function check(): ProbeResult
    {
        $paths = [
            'storage' => storage_path(),
            'bootstrap/cache' => base_path('bootstrap/cache'),
        ];

        $unwritable = [];

        foreach ($paths as $label => $path) {
            if (! is_dir($path) || ! is_writable($path)) {
                $unwritable[] = $label;
            }
        }

        if ($unwritable !== []) {
            return ProbeResult::fail($this->name(), 'Not writable: '.implode(', ', $unwritable).'.');
        }

        return ProbeResult::ok($this->name(), 'storage/ and bootstrap/cache are writable.');
    }
}
