<?php

declare(strict_types=1);

namespace Tests\Unit\Delivery;

use App\Domain\Delivery\Webhooks\RetrySchedule;
use PHPUnit\Framework\TestCase;

final class RetryScheduleTest extends TestCase
{
    public function test_it_walks_the_configured_delays_in_order(): void
    {
        $schedule = new RetrySchedule([60, 300, 1800], jitter: 0.0);

        $this->assertSame(60, $schedule->delayAfterAttempt(1));
        $this->assertSame(300, $schedule->delayAfterAttempt(2));
        $this->assertSame(1800, $schedule->delayAfterAttempt(3));
    }

    public function test_the_attempt_after_the_last_delay_is_not_made(): void
    {
        $schedule = new RetrySchedule([60, 300], jitter: 0.0);

        $this->assertNull($schedule->delayAfterAttempt(3));
        $this->assertSame(3, $schedule->maxAttempts());
    }

    public function test_jitter_stays_inside_its_fraction_of_the_delay(): void
    {
        $schedule = new RetrySchedule([1000], jitter: 0.1);

        for ($i = 0; $i < 50; $i++) {
            $delay = $schedule->delayAfterAttempt(1);

            $this->assertNotNull($delay);
            $this->assertGreaterThanOrEqual(900, $delay);
            $this->assertLessThanOrEqual(1100, $delay);
        }
    }

    public function test_it_reads_the_configured_shape_and_drops_nonsense_delays(): void
    {
        $schedule = RetrySchedule::fromConfig([
            'retry_delays' => [60, 0, -5, '300'],
            'retry_jitter' => 0,
        ]);

        $this->assertSame(60, $schedule->delayAfterAttempt(1));
        $this->assertSame(300, $schedule->delayAfterAttempt(2));
        $this->assertNull($schedule->delayAfterAttempt(3));
    }

    public function test_an_empty_schedule_means_one_attempt_and_no_retry(): void
    {
        $schedule = new RetrySchedule([]);

        $this->assertSame(1, $schedule->maxAttempts());
        $this->assertNull($schedule->delayAfterAttempt(1));
    }
}
