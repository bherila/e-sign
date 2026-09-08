<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Mail;

use App\Domain\Delivery\Mail\Jobs\SendOutboundMail;
use App\Domain\Delivery\Mail\Models\OutboundMail;
use App\Domain\Identity\Models\Workspace;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only supported way to send a transactional message.
 *
 * Nothing calls `Mail::send()` directly. Going through here is what produces the row that
 * answers "was this person told?", and a message with no row is a message nobody can prove
 * was sent or explain the absence of.
 *
 * `enqueue()` writes the row and the job in one transaction, and the job is dispatched
 * after commit. That ordering is the whole transactional-outbox pattern
 * (docs/HANDOFF.md section 11): a worker can never pick up a message whose row is not
 * visible yet, and a rolled-back caller cannot leave a job pointing at a row that no longer
 * exists.
 *
 * The state it writes is `queued`, which means only that the row exists.
 */
final class MailOutbox
{
    public function __construct(
        private readonly ProductionMailerGuard $guard,
        private readonly Repository $config,
    ) {}

    /**
     * @param  Model|null  $related  Whatever caused the message — an envelope, a recipient,
     *                               a webhook endpoint. Unconstrained: the outbox does not
     *                               need to know what those are.
     *
     * @throws NonDeliveringMailerException When production is configured with a mailer that
     *                                      cannot deliver.
     * @throws \InvalidArgumentException When the context cannot render this kind.
     */
    public function enqueue(
        MailKind $kind,
        MailRecipient $recipient,
        MailContext $context,
        ?Workspace $workspace = null,
        ?Model $related = null,
    ): OutboundMail {
        // Refuse before writing anything. A queued row in a deployment that cannot deliver
        // is worse than an exception: it looks like progress.
        $this->guard->assertDeliverable();

        // Built here, not just in the worker, for two reasons: the subject stored on the row
        // is then the subject that goes out, and a context that cannot render this kind
        // fails in the caller's stack trace rather than in a retry loop.
        $subject = $kind->mailable($context)->subjectLine();

        return DB::transaction(function () use ($kind, $recipient, $context, $workspace, $related, $subject): OutboundMail {
            $mail = new OutboundMail([
                'workspace_id' => $workspace?->getKey(),
                'kind' => $kind,
                'to_email' => $recipient->email,
                'to_name' => $recipient->name,
                'subject' => $subject,
                'context' => $context->toArray(),
                'state' => MailState::Queued,
                'state_changed_at' => Carbon::now(),
                'related_type' => $related?->getMorphClass(),
                'related_id' => $related === null ? null : (string) $related->getKey(),
            ]);

            $mail->save();

            $mail->recordEvent(MailEventSource::App, 'queued', [
                'mailer' => $this->guard->mailerName(),
                'kind' => $kind->value,
            ]);

            SendOutboundMail::dispatch($mail->public_id)
                ->onQueue((string) $this->config->get('esign.mail.queue'))
                ->afterCommit();

            return $mail;
        });
    }

    /**
     * Send the same content again as a new message.
     *
     * A new row rather than a reset of the old one. The original is evidence: it records
     * that an attempt was made, when, and how it ended, and rewinding its state would
     * destroy the only account of the failure that prompted the resend. The copy points
     * back at it through `resent_from_id`.
     *
     * The context is replayed verbatim, including its action URL. Whether that URL is still
     * valid is not this method's judgement to make — the credential's own expiry decides,
     * and an operator resending an expired link learns that from the recipient, not from a
     * silently rewritten message.
     */
    public function resend(OutboundMail $original): OutboundMail
    {
        $this->guard->assertDeliverable();

        return DB::transaction(function () use ($original): OutboundMail {
            $mail = new OutboundMail([
                'workspace_id' => $original->workspace_id,
                'kind' => $original->kind,
                'to_email' => $original->to_email,
                'to_name' => $original->to_name,
                'subject' => $original->subject,
                'context' => $original->context,
                'state' => MailState::Queued,
                'state_changed_at' => Carbon::now(),
                'related_type' => $original->related_type,
                'related_id' => $original->related_id,
                'resent_from_id' => $original->getKey(),
            ]);

            $mail->save();

            $mail->recordEvent(MailEventSource::App, 'queued', [
                'mailer' => $this->guard->mailerName(),
                'kind' => $mail->kind->value,
                'resent_from' => $original->public_id,
            ]);

            // Recorded on the original too, so reading one row explains what happened next
            // without a second query.
            $original->recordEvent(MailEventSource::App, 'resent', [
                'resent_as' => $mail->public_id,
            ]);

            SendOutboundMail::dispatch($mail->public_id)
                ->onQueue((string) $this->config->get('esign.mail.queue'))
                ->afterCommit();

            return $mail;
        });
    }
}
