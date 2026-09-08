<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Events;

use App\Domain\Delivery\Mail\MailRecipient;
use App\Domain\Identity\Enums\WorkspaceRole;
use App\Domain\Identity\Models\WorkspaceMembership;
use App\Domain\Signing\Models\Envelope;
use App\Domain\Signing\Models\EnvelopeRecipient;

/**
 * Who is told about an envelope, and who is not.
 *
 * Kept in one place because the answer is a policy rather than a detail, and because the
 * interesting case is the exclusion: a recipient who has never been invited is not told that
 * the agreement they were never shown has been cancelled or has expired. In sequential mode
 * that is most of the list. Silence there is not a gap — it is the only outcome that does not
 * disclose an agreement's existence, its title, and the other parties to somebody the sender
 * had not yet reached.
 *
 * `invited_at` is the fact that decides it, and it is written by this module when an
 * invitation is actually enqueued rather than inferred from a recipient's signing state.
 */
final readonly class EnvelopeAudience
{
    /**
     * Recipients who are eligible now and have not been written to yet.
     *
     * In sequential mode this is precisely the stage the last transition released: earlier
     * stages already carry an `invited_at`, later ones are still `pending`. In parallel mode
     * `send()` releases everyone at once and so does this.
     *
     * @return list<EnvelopeRecipient>
     */
    public static function uninvitedActive(Envelope $envelope): array
    {
        return array_values(
            $envelope->recipients()
                ->where('state', 'active')
                ->whereNull('invited_at')
                ->get()
                ->all()
        );
    }

    /**
     * Everyone who has been written to about this envelope, in signing order.
     *
     * @return list<EnvelopeRecipient>
     */
    public static function invited(Envelope $envelope): array
    {
        return array_values(
            $envelope->recipients()
                ->whereNotNull('invited_at')
                ->get()
                ->all()
        );
    }

    /**
     * The party the agreement is from: whoever created the envelope.
     *
     * Null when that account is gone. A completion or decline notice with nowhere to go is
     * reported to nobody rather than redirected to an administrator, who is not the sender
     * and should not receive a counterparty's decline reason by default.
     */
    public static function sender(Envelope $envelope): ?MailRecipient
    {
        $creator = $envelope->creator;

        if ($creator === null || ! is_string($creator->email) || trim($creator->email) === '') {
            return null;
        }

        return new MailRecipient($creator->email, is_string($creator->name) ? $creator->name : null);
    }

    /**
     * The name to show as "who this is from". The creator if there is one, otherwise the
     * workspace, which is the party a recipient would recognise.
     */
    public static function senderName(Envelope $envelope): string
    {
        $creator = $envelope->creator;

        if ($creator !== null && is_string($creator->name) && trim($creator->name) !== '') {
            return $creator->name;
        }

        $workspace = $envelope->workspace?->name;

        return ($workspace === null || trim($workspace) === '') ? 'The sender' : $workspace;
    }

    /**
     * Workspace owners, for operational failures.
     *
     * Owners rather than every administrator: a finalization failure needs somebody who can
     * authorise a retry or a re-send, and `owner` is the role the permission map gives that
     * authority to (`App\Domain\Identity\Enums\WorkspaceRole`).
     *
     * @return list<MailRecipient>
     */
    public static function owners(Envelope $envelope): array
    {
        $workspace = $envelope->workspace;

        if ($workspace === null) {
            return [];
        }

        $owners = [];

        /** @var WorkspaceMembership $membership */
        foreach ($workspace->memberships()->where('role', WorkspaceRole::Owner->value)->with('user')->get() as $membership) {
            $user = $membership->user;

            if ($user === null || ! is_string($user->email) || trim($user->email) === '') {
                continue;
            }

            $owners[$user->email] = new MailRecipient($user->email, is_string($user->name) ? $user->name : null);
        }

        return array_values($owners);
    }
}
