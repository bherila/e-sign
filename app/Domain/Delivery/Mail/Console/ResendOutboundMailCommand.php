<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Mail\Console;

use App\Domain\Delivery\Mail\MailOutbox;
use App\Domain\Delivery\Mail\Models\OutboundMail;
use App\Domain\Delivery\Mail\NonDeliveringMailerException;
use Illuminate\Console\Command;

/**
 * Send a message again, as a new message.
 *
 * The operator-facing half of MailOutbox::resend(). It never rewinds the original row: that
 * row is the record that an attempt was made and how it ended, and an operator resending a
 * bounced invitation should not thereby erase the bounce. The copy carries `resent_from_id`
 * so the pair reads as a sequence.
 *
 * It takes the public ULID, which is what appears in `esign:mail:backlog`, in the health
 * body, and in an event log. The autoincrement id is not an argument here and should not
 * become one.
 */
class ResendOutboundMailCommand extends Command
{
    protected $signature = 'esign:mail:resend
        {ulid : The public id of the message to resend}';

    protected $description = 'Queue a fresh copy of an outbound message, linked to the original.';

    public function handle(MailOutbox $outbox): int
    {
        $ulid = trim((string) $this->argument('ulid'));

        $original = OutboundMail::query()->where('public_id', $ulid)->first();

        if (! $original instanceof OutboundMail) {
            $this->error("No outbound message with public id '{$ulid}'.");
            $this->line('List recent messages with esign:mail:backlog --all.');

            return self::FAILURE;
        }

        try {
            $copy = $outbox->resend($original);
        } catch (NonDeliveringMailerException $exception) {
            // The guard's message names the mailer and the accepted set, which is the whole
            // of what an operator needs to fix it.
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Queued {$copy->public_id} as a resend of {$original->public_id}.");
        $this->line("Kind: {$copy->kind->value}. Subject: {$copy->subject}");
        $this->line('Run a worker on the mail queue if one is not already running.');

        return self::SUCCESS;
    }
}
