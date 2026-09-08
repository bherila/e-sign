<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Domain\Delivery\Health\HealthStatus;
use App\Domain\Delivery\Health\Probes\MailBacklogProbe;
use App\Domain\Delivery\Health\ReadinessChecker;
use App\Domain\Delivery\Mail\MailState;
use App\Domain\Delivery\Mail\Models\OutboundMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The probe that notices nothing is being sent.
 *
 * Distinct from the `mail` probe, which only checks that a delivering transport is
 * configured. The failure this one catches is the configuration-is-fine one: no worker on
 * the mail queue, so invitations sit at `queued` while every other signal stays green.
 */
class MailBacklogProbeTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_ok_with_an_empty_outbox(): void
    {
        $result = $this->probe()->check();

        $this->assertSame(HealthStatus::Ok, $result->status);
        $this->assertStringContainsString('No queued mail', $result->message);
    }

    public function test_is_ok_while_the_backlog_is_young(): void
    {
        OutboundMail::factory()->queuedAt(Carbon::now()->subSeconds(30))->create();

        $this->assertSame(HealthStatus::Ok, $this->probe()->check()->status);
    }

    public function test_warns_once_the_oldest_queued_message_passes_the_warn_threshold(): void
    {
        config()->set('esign.mail.backlog_warn_seconds', 300);
        config()->set('esign.mail.backlog_fail_seconds', 1800);

        OutboundMail::factory()->queuedAt(Carbon::now()->subSeconds(600))->create();

        $result = $this->probe()->check();

        $this->assertSame(HealthStatus::Warn, $result->status);
        $this->assertStringContainsString('Oldest queued message', $result->message);
    }

    public function test_fails_once_the_oldest_queued_message_passes_the_fail_threshold(): void
    {
        config()->set('esign.mail.backlog_fail_seconds', 1800);

        OutboundMail::factory()->queuedAt(Carbon::now()->subSeconds(3600))->create();

        $this->assertSame(HealthStatus::Fail, $this->probe()->check()->status);
    }

    public function test_a_message_already_handed_to_a_provider_is_not_backlog(): void
    {
        // Out of this application's hands. Counting it would make a working deployment look
        // broken every time a provider was slow to send feedback.
        OutboundMail::factory()
            ->sentToProvider()
            ->create(['created_at' => Carbon::now()->subDays(3)]);

        $result = $this->probe()->check();

        $this->assertSame(HealthStatus::Ok, $result->status);
        $this->assertStringContainsString('No queued mail', $result->message);
    }

    public function test_a_recent_failure_warns_and_many_fail(): void
    {
        config()->set('esign.mail.failed_warn_count', 1);
        config()->set('esign.mail.failed_fail_count', 3);

        OutboundMail::factory()->inState(MailState::Failed)->create();
        $this->assertSame(HealthStatus::Warn, $this->probe()->check()->status);

        OutboundMail::factory()->count(2)->inState(MailState::Failed)->create();
        $result = $this->probe()->check();

        $this->assertSame(HealthStatus::Fail, $result->status);
        $this->assertStringContainsString('3 message(s) failed in the last 24h', $result->message);
    }

    public function test_an_old_failure_is_outside_the_window(): void
    {
        config()->set('esign.mail.failed_warn_count', 1);

        OutboundMail::factory()->create([
            'state' => MailState::Failed,
            'state_changed_at' => Carbon::now()->subDays(2),
        ]);

        $this->assertSame(HealthStatus::Ok, $this->probe()->check()->status);
    }

    public function test_the_message_never_names_the_recipient_or_the_subject(): void
    {
        OutboundMail::factory()->queuedAt(Carbon::now()->subHour())->create([
            'to_email' => 'avery@counterparty.test',
            'subject' => 'Please sign the Mutual Nondisclosure Agreement',
        ]);

        $message = $this->probe()->check()->message;

        // The readiness body is operational detail, not a list of who is being emailed
        // about what. See docs/operations/health.md.
        $this->assertStringNotContainsString('avery@counterparty.test', $message);
        $this->assertStringNotContainsString('Nondisclosure', $message);
    }

    public function test_it_is_part_of_the_readiness_report(): void
    {
        $report = $this->app->make(ReadinessChecker::class)->run()->toArray();

        $this->assertArrayHasKey('mail_backlog', $report['probes']);
    }

    private function probe(): MailBacklogProbe
    {
        return $this->app->make(MailBacklogProbe::class);
    }
}
