<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Health\Probes;

use App\Domain\Delivery\Health\HealthProbe;
use App\Domain\Delivery\Health\ProbeResult;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Writes, reads back, and deletes a small probe file on the default disk.
 * Never reports the disk root path or driver.
 */
final class StorageProbe implements HealthProbe
{
    public function name(): string
    {
        return 'storage';
    }

    public function check(): ProbeResult
    {
        $disk = Storage::disk(config('filesystems.default'));
        $path = 'health-checks/'.Str::uuid()->toString().'.probe';
        $contents = (string) random_int(100000, 999999);

        try {
            $disk->put($path, $contents);
            $roundTripped = $disk->get($path);

            if ($roundTripped !== $contents) {
                return ProbeResult::fail($this->name(), 'Storage round-trip returned unexpected contents.');
            }

            return ProbeResult::ok($this->name(), 'Write/read/delete succeeded on the default disk.');
        } catch (Throwable) {
            return ProbeResult::fail($this->name(), 'Storage write/read failed on the default disk.');
        } finally {
            try {
                $disk->delete($path);
            } catch (Throwable) {
                // Best-effort cleanup; a delete failure does not change the probe result
                // beyond what was already determined above.
            }
        }
    }
}
