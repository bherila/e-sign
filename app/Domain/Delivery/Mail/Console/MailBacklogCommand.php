<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Mail\Console;

use App\Domain\Delivery\Mail\MailState;
use App\Domain\Delivery\Mail\Models\OutboundMail;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * What is stuck, what was given up on, and how the outbox is distributed across states.
 *
 * The same two numbers MailBacklogProbe reports, plus the rows behind them. The probe
 * answers "is this deployment healthy" for a monitor; this answers "which messages, and
 * what went wrong" for the person who was just paged.
 *
 * Addresses are shown. This is a console command run by an operator who already has
 * database access, and a backlog listing with the recipients redacted cannot be acted on.
 * Nothing here is written to a log or a response body.
 */
class MailBacklogCommand extends Command
{
    protected $signature = 'esign:mail:backlog
        {--limit=20 : How many rows to list}
        {--all : List recent messages in every state, not only queued and failed}';

    protected $description = 'Show queued and failed outbound mail, oldest first.';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $counts = OutboundMail::query()
            ->selectRaw('state, count(*) as total')
            ->groupBy('state')
            ->pluck('total', 'state');

        $this->line('Outbox by state:');

        foreach (MailState::cases() as $state) {
            $this->line(sprintf('  %-18s %d', $state->value, (int) ($counts[$state->value] ?? 0)));
        }

        $oldestQueued = OutboundMail::query()
            ->inState(MailState::Queued)
            ->orderBy('created_at')
            ->first();

        $this->newLine();
        $this->line($oldestQueued === null
            ? 'Nothing is queued.'
            : sprintf(
                'Oldest queued message has been waiting %s (%s).',
                $oldestQueued->created_at?->diffForHumans(syntax: Carbon::DIFF_ABSOLUTE) ?? 'an unknown time',
                $oldestQueued->public_id,
            ));

        $failedLast24h = OutboundMail::query()
            ->inState(MailState::Failed)
            ->where('state_changed_at', '>=', Carbon::now()->subDay())
            ->count();

        $this->line("{$failedLast24h} message(s) reached failed in the last 24h.");

        $rows = OutboundMail::query()
            ->when(
                ! (bool) $this->option('all'),
                fn ($query) => $query->whereIn('state', [MailState::Queued->value, MailState::Failed->value]),
            )
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            $this->newLine();
            $this->info('No messages to list.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->table(
            ['Public id', 'Kind', 'State', 'To', 'Attempts', 'Created', 'Last error'],
            $rows->map(fn (OutboundMail $mail): array => [
                $mail->public_id,
                $mail->kind->value,
                $mail->state->value,
                $mail->to_email,
                (string) $mail->attempts,
                $mail->created_at?->toDateTimeString() ?? '—',
                $mail->last_error ?? '—',
            ])->all(),
        );

        $this->line('Resend one with esign:mail:resend <public id>.');

        return self::SUCCESS;
    }
}
