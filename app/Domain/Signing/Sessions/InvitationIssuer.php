<?php

declare(strict_types=1);

namespace App\Domain\Signing\Sessions;

use App\Domain\Identity\Audit\AuditActor;
use App\Domain\Identity\Audit\AuditRecorder;
use App\Domain\Signing\Models\Envelope;
use App\Domain\Signing\Models\EnvelopeRecipient;
use App\Domain\Signing\Sessions\Exceptions\GuestAccessDenied;
use App\Domain\Signing\Sessions\Models\RecipientInvitation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * Mints, rotates, and retires the credential a recipient reaches an envelope with.
 *
 * One recipient has at most one live invitation. Issuing a second revokes the first, which
 * is what makes "resend the link" mean something: the address that received the earlier mail
 * loses the ability to sign, so a message forwarded to the wrong person stops working the
 * moment the sender notices and reissues. Keeping both alive would make every resend widen
 * the set of people who can execute the agreement.
 *
 * This class mints URLs and does not send anything. docs/delivery/mail.md's invitation
 * Mailable takes an already-authorized URL string and neither builds nor signs one, and the
 * split is what keeps a mail template out of the set of files that have to be reviewed like
 * a credential issuer.
 *
 * Every decision is audited. The payloads name the invitation's `public_id`, the recipient,
 * and the envelope, and never the token or the URL — a credential in an append-only table
 * is a credential nobody can withdraw.
 */
final class InvitationIssuer
{
    /**
     * Longest TTL an explicit argument may ask for: 90 days.
     *
     * A ceiling rather than a validation nicety. An invitation is a bearer credential in a
     * mailbox, and a caller that asks for a two-year one has almost certainly made an
     * arithmetic mistake; refusing is better than silently minting it.
     */
    public const MAX_TTL_HOURS = 2_160;

    public function __construct(
        private readonly Repository $config,
        private readonly UrlGenerator $url,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * A fresh credential for this recipient, revoking any that were live.
     *
     * @param  int|null  $ttlHours  Defaults to `esign.signing.invitation_ttl_hours`.
     */
    public function issue(
        EnvelopeRecipient $recipient,
        ?int $ttlHours = null,
        ?User $issuedBy = null,
    ): IssuedInvitation {
        return $this->mint($recipient, $ttlHours, $issuedBy, 'superseded');
    }

    /**
     * The same operation, named for what a sender is doing when they ask for it again.
     *
     * Kept as a separate entry point rather than a flag because the audit trail should say
     * "reissued" where a person pressed a resend button, and "issued" where the envelope was
     * sent. Both revoke what came before; only the recorded reason differs.
     */
    public function reissue(
        EnvelopeRecipient $recipient,
        ?int $ttlHours = null,
        ?User $issuedBy = null,
    ): IssuedInvitation {
        return $this->mint($recipient, $ttlHours, $issuedBy, 'reissued');
    }

    /**
     * Withdraw every live credential for a recipient without minting a replacement.
     *
     * Returns how many rows were affected, so a caller can tell "revoked one" from "there
     * was nothing to revoke" instead of assuming.
     */
    public function revokeAllFor(EnvelopeRecipient $recipient, string $reason): int
    {
        $now = CarbonImmutable::now();

        $revoked = RecipientInvitation::query()
            ->where('recipient_id', $recipient->getKey())
            ->live($now)
            ->update([
                'revoked_at' => $now,
                'revoked_reason' => mb_substr($reason, 0, 64),
                'updated_at' => $now,
            ]);

        if ($revoked > 0) {
            $this->audit->record(
                AuditActor::system('signing.invitations'),
                'signing.invitation.revoked',
                $recipient,
                ['count' => $revoked, 'reason' => $reason],
            );
        }

        return $revoked;
    }

    /**
     * Find the live invitation a presented token stands for.
     *
     * The envelope is checked against the row rather than trusted from the path: the URL
     * carries both, and a token minted for one envelope must not be replayable against
     * another by editing the identifier next to it.
     *
     * A malformed token never reaches the database — {@see SigningToken::looksWellFormed()}
     * costs a regular expression and turns a scanner hammering the path into no query at
     * all — and every failure below returns the same coarse refusal to the caller.
     *
     * @throws GuestAccessDenied
     */
    public function resolve(Envelope $envelope, #[SensitiveParameter] string $token): RecipientInvitation
    {
        if (! SigningToken::looksWellFormed($token)) {
            throw GuestAccessDenied::unknownInvitation();
        }

        $invitation = RecipientInvitation::query()
            ->where('token_hash', SigningToken::hash($token))
            ->where('envelope_id', $envelope->getKey())
            ->with('recipient')
            ->first();

        if ($invitation === null) {
            throw GuestAccessDenied::unknownInvitation();
        }

        $refusal = $invitation->refusalReason();

        if ($refusal !== null) {
            throw GuestAccessDenied::invitationNotLive($refusal);
        }

        return $invitation;
    }

    /**
     * Mark an invitation as spent.
     *
     * Called only from the explicit POST that establishes a session, never from a GET —
     * that is the whole of AGENTS.md's "GET is harmless" as far as this table is concerned.
     * The compare-and-swap on `consumed_at IS NULL` is what makes two simultaneous POSTs
     * produce one session rather than two: the loser sees zero affected rows and is told the
     * credential is spent.
     */
    public function consume(RecipientInvitation $invitation): bool
    {
        $now = CarbonImmutable::now();

        $consumed = RecipientInvitation::query()
            ->whereKey($invitation->getKey())
            ->whereNull('consumed_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', $now)
            ->update(['consumed_at' => $now, 'updated_at' => $now]);

        if ($consumed !== 1) {
            return false;
        }

        $invitation->forceFill(['consumed_at' => $now]);

        $this->audit->record(
            AuditActor::system('signing.invitations'),
            'signing.invitation.consumed',
            $invitation,
            ['recipient' => $invitation->recipient?->public_id, 'envelope_id' => $invitation->envelope_id],
        );

        return true;
    }

    /** The absolute URL a given plaintext token addresses. One definition, used everywhere. */
    public function urlFor(Envelope $envelope, #[SensitiveParameter] string $token): string
    {
        return $this->url->route('signing.landing', [
            'envelope' => $envelope->public_id,
            'token' => $token,
        ], absolute: true);
    }

    private function mint(
        EnvelopeRecipient $recipient,
        ?int $ttlHours,
        ?User $issuedBy,
        string $supersedeReason,
    ): IssuedInvitation {
        $envelope = $recipient->envelope()->first();

        if (! $envelope instanceof Envelope) {
            // A recipient with no envelope is a corrupted row, not a request problem.
            throw (new ModelNotFoundException)->setModel(Envelope::class, [$recipient->envelope_id]);
        }

        $hours = $this->ttlHours($ttlHours);
        $token = SigningToken::generate();
        $expiresAt = CarbonImmutable::now()->addHours($hours);

        $invitation = DB::transaction(function () use ($recipient, $envelope, $token, $expiresAt, $issuedBy, $supersedeReason): RecipientInvitation {
            $this->revokeAllFor($recipient, $supersedeReason);

            return RecipientInvitation::query()->create([
                'recipient_id' => $recipient->getKey(),
                'envelope_id' => $envelope->getKey(),
                'token_hash' => SigningToken::hash($token),
                'expires_at' => $expiresAt,
                'issued_by' => $issuedBy?->getKey(),
            ]);
        });

        $this->audit->record(
            $issuedBy === null
                ? AuditActor::system('signing.invitations')
                : AuditActor::user($issuedBy),
            'signing.invitation.'.($supersedeReason === 'reissued' ? 'reissued' : 'issued'),
            $invitation,
            [
                'recipient' => $recipient->public_id,
                'envelope' => $envelope->public_id,
                'expires_at' => $expiresAt->toIso8601String(),
                'ttl_hours' => $hours,
            ],
        );

        return new IssuedInvitation(
            $invitation,
            $this->urlFor($envelope, $token),
            $expiresAt,
        );
    }

    private function ttlHours(?int $requested): int
    {
        $hours = $requested ?? (int) $this->config->get('esign.signing.invitation_ttl_hours', 168);

        if ($hours < 1 || $hours > self::MAX_TTL_HOURS) {
            throw new InvalidArgumentException(
                'An invitation lifetime must be between 1 and '.self::MAX_TTL_HOURS.' hours; got '.$hours.'.',
            );
        }

        return $hours;
    }
}
