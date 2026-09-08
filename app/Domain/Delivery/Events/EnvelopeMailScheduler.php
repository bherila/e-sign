<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Events;

use App\Domain\Delivery\Mail\MailContext;
use App\Domain\Delivery\Mail\MailKind;
use App\Domain\Delivery\Mail\MailOutbox;
use App\Domain\Delivery\Mail\MailRecipient;
use App\Domain\Signing\Envelopes\EnvelopeEvent;
use App\Domain\Signing\Envelopes\EnvelopeState;
use App\Domain\Signing\Envelopes\RecipientState;
use App\Domain\Signing\Models\Envelope;
use App\Domain\Signing\Models\EnvelopeRecipient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What each envelope event means for the people involved.
 *
 * Runs **after** the transition commits, never inside it. Mail is an at-least-once transport
 * with no undo: a message enqueued inside a transaction that later rolls back has already
 * told somebody something that did not happen (`docs/HANDOFF.md` §11). The event sink
 * therefore records the outbox row in the caller's transaction and hands this class to
 * {@see Jobs\ScheduleEnvelopeMail}, dispatched after commit.
 *
 * The mapping, in one place so `docs/delivery/envelope-events.md` has a single source:
 *
 * | Event | Who is written to |
 * |---|---|
 * | `signing_request.sent` | every recipient the send released, with a signing link |
 * | `signing_request.recipient.signed` | the recipients that acceptance released, and nobody else |
 * | `signing_request.completed` | every recipient, and the sender |
 * | `esign.envelope.declined` | the sender |
 * | `signing_request.cancelled` | every invited recipient, and the sender |
 * | `signing_request.expired` | every invited recipient, and the sender |
 * | `esign.envelope.finalization.failed` | the workspace's owners |
 *
 * A recipient signing is **not** a completion. It releases the next stage and produces
 * invitations, and it produces nothing else — no "one down, one to go" notice to the sender,
 * which would be a second event on the wire that the profile does not have.
 *
 * `signing_request.created` and `signing_request.recipient.declined` schedule no mail at all.
 * A draft has told nobody anything yet, and the envelope-level `esign.envelope.declined`
 * event that always accompanies a recipient decline is where that message belongs — sending
 * from both would double every decline notice.
 */
final readonly class EnvelopeMailScheduler
{
    public function __construct(
        private MailOutbox $outbox,
        private SigningUrlMinter $signingUrls,
        private DownloadUrlMinter $downloadUrls,
    ) {}

    public function schedule(Envelope $envelope, EnvelopeEvent $event): void
    {
        match ($event) {
            EnvelopeEvent::Sent, EnvelopeEvent::RecipientSigned => $this->invite($envelope),
            EnvelopeEvent::Completed => $this->announceCompletion($envelope),
            EnvelopeEvent::Declined => $this->reportDecline($envelope),
            EnvelopeEvent::Cancelled => $this->reportCancellation($envelope),
            EnvelopeEvent::Expired => $this->reportExpiry($envelope),
            EnvelopeEvent::FinalizationFailed => $this->alertOwners($envelope),
            EnvelopeEvent::Created, EnvelopeEvent::RecipientDeclined => null,
        };
    }

    /**
     * Invite whoever this transition made eligible.
     *
     * Re-read at send time rather than captured when the event was recorded, and guarded on
     * the envelope's state: between the commit and the worker picking the job up, the
     * envelope can have been cancelled, declined, or expired, and an invitation to sign
     * something that is already dead is worse than a late one.
     *
     * `invited_at` is stamped before the message is enqueued, inside the same transaction the
     * outbox opens for the row, so two workers racing the same job produce one invitation.
     */
    private function invite(Envelope $envelope): void
    {
        if (! in_array($envelope->state, [EnvelopeState::Sent, EnvelopeState::InProgress], true)) {
            return;
        }

        foreach (EnvelopeAudience::uninvitedActive($envelope) as $recipient) {
            // Minted before anything is claimed or written. The placeholder minter throws,
            // and a throw here must leave the recipient exactly as uninvited as they were,
            // so the job can be retried once guest access is bound.
            $signingUrl = $this->signingUrls->signingUrlFor($recipient);

            DB::transaction(function () use ($envelope, $recipient, $signingUrl): void {
                if (! $this->claimInvitation($recipient)) {
                    return;
                }

                $this->outbox->enqueue(
                    kind: MailKind::Invitation,
                    recipient: self::addressOf($recipient),
                    context: new MailContext(
                        recipientName: $recipient->name,
                        senderName: EnvelopeAudience::senderName($envelope),
                        agreementTitle: $envelope->title,
                        actionUrl: $signingUrl,
                        expiresAt: $envelope->expires_at,
                    ),
                    workspace: $envelope->workspace,
                    related: $recipient,
                );
            });
        }
    }

    private function announceCompletion(Envelope $envelope): void
    {
        // The gate is here rather than only in the port: "never a link to an artifact that
        // is not published" is a product rule (AGENTS.md, "Fail closed"), and a rule that
        // only holds when every implementation remembers it is not a rule.
        $downloadUrl = $envelope->artifact_ref === null
            ? null
            : $this->downloadUrls->downloadUrlFor($envelope);
        $senderName = EnvelopeAudience::senderName($envelope);

        foreach ($envelope->recipients()->get() as $recipient) {
            $this->outbox->enqueue(
                kind: MailKind::Completed,
                recipient: self::addressOf($recipient),
                context: new MailContext(
                    recipientName: $recipient->name,
                    senderName: $senderName,
                    agreementTitle: $envelope->title,
                    actionUrl: $downloadUrl,
                ),
                workspace: $envelope->workspace,
                related: $envelope,
            );
        }

        $sender = EnvelopeAudience::sender($envelope);

        if ($sender !== null) {
            $this->outbox->enqueue(
                kind: MailKind::Completed,
                recipient: $sender,
                context: new MailContext(
                    recipientName: $sender->name ?? $senderName,
                    senderName: $senderName,
                    agreementTitle: $envelope->title,
                    actionUrl: $downloadUrl,
                ),
                workspace: $envelope->workspace,
                related: $envelope,
            );
        }
    }

    /**
     * A decline goes to the sender and stops there.
     *
     * `resources/views/mail/declined.blade.php` is written to the sender and carries the
     * reason the recipient gave. Forwarding that reason to the other parties would publish
     * one counterparty's explanation to the rest, which is not the sender's decision to have
     * made for them.
     */
    private function reportDecline(Envelope $envelope): void
    {
        $sender = EnvelopeAudience::sender($envelope);

        if ($sender === null) {
            return;
        }

        $decliner = $envelope->recipients()
            ->where('state', RecipientState::Declined->value)
            ->orderByDesc('declined_at')
            ->first();

        $this->outbox->enqueue(
            kind: MailKind::Declined,
            recipient: $sender,
            context: new MailContext(
                recipientName: $sender->name ?? EnvelopeAudience::senderName($envelope),
                senderName: EnvelopeAudience::senderName($envelope),
                agreementTitle: $envelope->title,
                actorName: $decliner?->name ?? 'A recipient',
                reason: $decliner?->decline_reason,
            ),
            workspace: $envelope->workspace,
            related: $envelope,
        );
    }

    private function reportCancellation(Envelope $envelope): void
    {
        // The state machine records why an envelope was withdrawn but not who withdrew it,
        // and the envelope's creator is the party a recipient was told it came from. Naming
        // them is the closest true statement available; inventing an actor would not be.
        $actor = EnvelopeAudience::senderName($envelope);

        $this->tellEveryoneInvited($envelope, MailKind::Cancelled, fn (string $name): MailContext => new MailContext(
            recipientName: $name,
            senderName: $actor,
            agreementTitle: $envelope->title,
            actorName: $actor,
            reason: $envelope->cancel_reason,
        ));
    }

    private function reportExpiry(Envelope $envelope): void
    {
        $this->tellEveryoneInvited($envelope, MailKind::Expired, fn (string $name): MailContext => new MailContext(
            recipientName: $name,
            senderName: EnvelopeAudience::senderName($envelope),
            agreementTitle: $envelope->title,
            expiresAt: $envelope->expires_at,
        ));
    }

    /**
     * @param  callable(string): MailContext  $context
     */
    private function tellEveryoneInvited(Envelope $envelope, MailKind $kind, callable $context): void
    {
        foreach (EnvelopeAudience::invited($envelope) as $recipient) {
            $this->outbox->enqueue(
                kind: $kind,
                recipient: self::addressOf($recipient),
                context: $context($recipient->name),
                workspace: $envelope->workspace,
                related: $envelope,
            );
        }

        $sender = EnvelopeAudience::sender($envelope);

        if ($sender !== null) {
            $this->outbox->enqueue(
                kind: $kind,
                recipient: $sender,
                context: $context($sender->name ?? EnvelopeAudience::senderName($envelope)),
                workspace: $envelope->workspace,
                related: $envelope,
            );
        }
    }

    /**
     * A failed finalization is an operational problem, not a signing one.
     *
     * The signers are told nothing: from their side the agreement is signed and waiting, the
     * state machine keeps it visibly `finalization_failed` rather than completed, and a retry
     * that succeeds needs no correction to have been mailed out.
     */
    private function alertOwners(Envelope $envelope): void
    {
        foreach (EnvelopeAudience::owners($envelope) as $owner) {
            $this->outbox->enqueue(
                kind: MailKind::AdminFailure,
                recipient: $owner,
                context: new MailContext(
                    recipientName: $owner->name ?? 'Workspace owner',
                    agreementTitle: $envelope->title,
                    failureSummary: 'Finalization did not produce a validated, retrievable PDF. '
                        .'The agreement is signed and is being held as finalization_failed; it has not been completed.',
                    reason: $envelope->finalization_failure_reason,
                    reference: 'envelope '.$envelope->public_id,
                ),
                workspace: $envelope->workspace,
                related: $envelope,
            );
        }
    }

    /**
     * Take this recipient's invitation, or report that somebody else already has.
     *
     * A conditional update rather than a read-then-write: two workers running the same job
     * would both see a null `invited_at` and both send. `UPDATE … WHERE invited_at IS NULL`
     * lets exactly one of them through, which is the same compare-and-swap discipline the
     * state machine uses for transitions.
     */
    private function claimInvitation(EnvelopeRecipient $recipient): bool
    {
        $now = CarbonImmutable::now();

        $claimed = $recipient->newQuery()
            ->whereKey($recipient->getKey())
            ->whereNull('invited_at')
            ->update(['invited_at' => $now]);

        if ($claimed !== 1) {
            return false;
        }

        $recipient->invited_at = $now;
        $recipient->syncOriginalAttribute('invited_at');

        return true;
    }

    private static function addressOf(EnvelopeRecipient $recipient): MailRecipient
    {
        return new MailRecipient($recipient->email, $recipient->name);
    }
}
