<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Domain\Delivery\Health\SchedulerHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class DoctorCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_succeeds_when_nothing_actually_fails(): void
    {
        config()->set('queue.default', 'database');

        // Resource limits depend on this machine's own php.ini, which is out of this test's
        // control and is covered on its own in ResourceLimitsProbeTest; disable the floor here
        // so it cannot fail this test on a CLI configured with a lower memory_limit than the
        // deployment minimum.
        config()->set('esign.cpanel.min_memory_bytes', 1);
        config()->set('esign.cpanel.min_execution_seconds', 0);

        // A brand new install has no scheduler heartbeat yet: that is not a bug in the
        // command, it is the honest "cron has not been observed to run" state. Record one so
        // this test exercises the fully-healthy path deliberately, the same way an operator
        // would re-run esign:doctor after cron has ticked once.
        $this->app->make(SchedulerHeartbeat::class)->record();

        // web_php_version and signing_material both warn rather than fail outside a
        // configured production install (ESIGN_CPANEL_WEB_PHP_VERSION and the seal key are both
        // legitimately unset in local development), so the overall result is "degraded", not a
        // spotless "all checks passed" — the same ok/degraded/fail vocabulary /health/ready uses.
        // The exit code is still success: nothing here actually failed.
        $this->artisan('esign:doctor')
            ->doesntExpectOutputToContain('One or more checks failed')
            ->assertSuccessful();
    }

    public function test_it_exits_non_zero_when_a_check_fails(): void
    {
        config()->set('queue.default', 'sync');

        $this->artisan('esign:doctor')
            ->expectsOutputToContain('One or more checks failed')
            ->assertFailed();
    }

    public function test_it_never_prints_the_app_key(): void
    {
        config()->set('queue.default', 'database');

        $appKey = (string) config('app.key');
        $this->assertNotSame('', $appKey);

        Artisan::call('esign:doctor');

        $this->assertStringNotContainsString($appKey, Artisan::output());
    }
}
