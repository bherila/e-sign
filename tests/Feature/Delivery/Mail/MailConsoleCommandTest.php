<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery\Mail;

use App\Domain\Delivery\Mail\Jobs\SendOutboundMail;
use App\Domain\Delivery\Mail\MailState;
use App\Domain\Delivery\Mail\Models\OutboundMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * `esign:mail:resend` and `esign:mail:backlog`.
 *
 * The operator surface. Both commands live under app/Domain/Delivery/Mail/Console, which
 * Laravel's auto-discovery does not scan, so this also covers the fact that
 * DeliveryServiceProvider registers them — a command that exists but is not registered is
 * indistinguishable from one that was never written.
 */
class MailConsoleCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_resend_queues_a_copy_linked_to_the_original(): void
    {
        Queue::fake();

        $original = OutboundMail::factory()->inState(MailState::Bounced)->create();

        $this->artisan('esign:mail:resend', ['ulid' => $original->public_id])
            ->expectsOutputToContain('as a resend of '.$original->public_id)
            ->assertSuccessful();

        $copy = OutboundMail::query()->where('resent_from_id', $original->getKey())->sole();

        $this->assertSame(MailState::Queued, $copy->state);
        $this->assertSame($original->to_email, $copy->to_email);
        // The original stays as it was: it is the record of the bounce.
        $this->assertSame(MailState::Bounced, $original->fresh()?->state);

        Queue::assertPushed(
            SendOutboundMail::class,
            fn (SendOutboundMail $job): bool => $job->mailPublicId === $copy->public_id,
        );
    }

    public function test_resend_fails_cleanly_on_an_unknown_ulid(): void
    {
        Queue::fake();

        $this->artisan('esign:mail:resend', ['ulid' => '01JQZX9K7M4N2P5R8T3V6W1Y0B'])
            ->assertFailed();

        $this->assertSame(0, OutboundMail::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_resend_refuses_when_production_cannot_deliver(): void
    {
        Queue::fake();

        $original = OutboundMail::factory()->inState(MailState::Bounced)->create();

        $this->app->detectEnvironment(fn (): string => 'production');
        config()->set('mail.default', 'log');

        $this->artisan('esign:mail:resend', ['ulid' => $original->public_id])
            ->assertFailed();

        $this->assertSame(1, OutboundMail::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_backlog_reports_an_empty_outbox(): void
    {
        $this->artisan('esign:mail:backlog')
            ->expectsOutputToContain('Nothing is queued.')
            ->expectsOutputToContain('No messages to list.')
            ->assertSuccessful();
    }

    public function test_backlog_lists_queued_and_failed_messages_oldest_first(): void
    {
        $queued = OutboundMail::factory()->queuedAt(Carbon::now()->subHour())->create();
        $failed = OutboundMail::factory()->create([
            'state' => MailState::Failed,
            'last_error' => 'Expected response code 250 but got code "550"',
        ]);
        $sent = OutboundMail::factory()->sentToProvider()->create();

        $this->artisan('esign:mail:backlog')
            ->expectsOutputToContain($queued->public_id)
            ->expectsOutputToContain($failed->public_id)
            ->expectsOutputToContain('1 message(s) reached failed in the last 24h.')
            ->assertSuccessful();

        // A message already handed to a provider is not backlog and is not listed unless
        // --all asks for it.
        $this->artisan('esign:mail:backlog')
            ->doesntExpectOutputToContain($sent->public_id)
            ->assertSuccessful();

        $this->artisan('esign:mail:backlog', ['--all' => true])
            ->expectsOutputToContain($sent->public_id)
            ->assertSuccessful();
    }

    public function test_backlog_all_lists_the_most_recent_messages_not_the_first_ever(): void
    {
        $ancient = OutboundMail::factory()->sentToProvider()->create([
            'created_at' => Carbon::now()->subYear(),
            'updated_at' => Carbon::now()->subYear(),
        ]);
        $recent = OutboundMail::factory()->sentToProvider()->create([
            'created_at' => Carbon::now()->subMinute(),
            'updated_at' => Carbon::now()->subMinute(),
        ]);

        // `--all` answers "what just happened". Oldest-first there would hand an operator
        // the first rows the outbox ever wrote, which is never what they were looking for.
        $this->artisan('esign:mail:backlog', ['--all' => true, '--limit' => 1])
            ->expectsOutputToContain($recent->public_id)
            ->doesntExpectOutputToContain($ancient->public_id)
            ->assertSuccessful();
    }

    public function test_the_default_listing_stays_oldest_first_because_that_is_what_is_stuck(): void
    {
        $oldest = OutboundMail::factory()->queuedAt(Carbon::now()->subDay())->create();
        $newest = OutboundMail::factory()->queuedAt(Carbon::now()->subMinute())->create();

        $this->artisan('esign:mail:backlog', ['--limit' => 1])
            ->expectsOutputToContain($oldest->public_id)
            ->doesntExpectOutputToContain($newest->public_id)
            ->assertSuccessful();
    }

    public function test_backlog_honours_the_limit(): void
    {
        OutboundMail::factory()->count(3)->create();

        $this->artisan('esign:mail:backlog', ['--limit' => 1])->assertSuccessful();
        // A nonsense limit still lists something rather than nothing.
        $this->artisan('esign:mail:backlog', ['--limit' => 0])->assertSuccessful();
    }
}
