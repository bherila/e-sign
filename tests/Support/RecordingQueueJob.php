<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * A minimal real job (not a fake/mock) used to prove esign:queue:work-bounded actually drains
 * the database queue connection rather than merely reporting success. Records its own
 * completion under a caller-chosen cache key so the test can assert it really ran.
 */
final class RecordingQueueJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $cacheKey) {}

    public function handle(): void
    {
        Cache::forever($this->cacheKey, true);
    }
}
