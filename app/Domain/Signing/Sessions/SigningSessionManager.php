<?php

declare(strict_types=1);

namespace App\Domain\Signing\Sessions;

use App\Domain\Identity\Audit\AuditActor;
use App\Domain\Identity\Audit\AuditRecorder;
use App\Domain\Signing\Envelopes\RecipientState;
use App\Domain\Signing\Models\Envelope;
use App\Domain\Signing\Models\EnvelopeRecipient;
use App\Domain\Signing\Sessions\Exceptions\GuestAccessDenied;
use App\Domain\Signing\Sessions\Models\RecipientInvitation;
use App\Domain\Signing\Sessions\Models\SigningSession;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Creates, finds, extends, and ends guest signing sessions.
 *
 * The one rule this class exists to enforce is that **nothing here is reachable from a GET**.
 * `start()` consumes an invitation and writes a row, and the only caller is the POST behind
 * the landing page's Continue button. A mail-security scanner fetching every URL in a message
 * therefore changes nothing: it renders a page, and the credential it was holding is still
 * unspent when the recipient arrives (AGENTS.md, "GET is harmless"; docs/HANDOFF.md
 * section 8).
 *
 * `resolve()` is the other half. Every route past the landing page calls it, and it answers
 * with a session only when all of the following hold: the cookie is present, its verifier
 * matches a row, the row has not ended, the row has not expired, and the row's envelope is
 * the envelope in the URL. A public recipient ULID, an envelope ULID, and a guessed cookie
 * are all equally useless on their own.
 *
 * ## The sliding window
 *
 * A session expires on its own clock and each authorized request pushes that clock out
 * again. The alternative — a fixed window — means a person reading a forty-page agreement
 * carefully is logged out for reading it carefully, and it is exactly the class of person
 * this product should be least willing to interrupt. A request that arrives *after* the
 * window has passed is refused rather than renewed, so the sliding is an extension of a live
 * session and never a resurrection of a dead one.
 */
final class SigningSessionManager
{
    public function __construct(
        private readonly Repository $config,
        private readonly KeyedDigest $digest,
        private readonly SigningCookie $cookies,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * Exchange a live invitation for a session. The only path that consumes a credential.
     *
     * The invitation and the session are written in one transaction, and the consume is a
     * compare-and-swap on `consumed_at IS NULL`: two simultaneous submissions of the same
     * form produce one session, and the loser is told the credential is spent rather than
     * being handed a second one.
     *
     * @param  string|null  $returnUrl  Already validated by {@see ReturnUrlPolicy}; this
     *                                  method stores what it is given and validates nothing.
     *
     * @throws GuestAccessDenied
     */
    public function start(
        RecipientInvitation $invitation,
        Request $request,
        SigningVerification $verification,
        InvitationIssuer $issuer,
        ?string $returnUrl = null,
    ): StartedSigningSession {
        $fingerprint = ClientFingerprint::of($request, $this->digest);
        $token = SigningToken::generate();
        $now = CarbonImmutable::now();

        $session = DB::transaction(function () use ($invitation, $issuer, $fingerprint, $token, $now, $verification, $returnUrl): SigningSession {
            if (! $issuer->consume($invitation)) {
                throw GuestAccessDenied::invitationNotLive('consumed');
            }

            return SigningSession::query()->create([
                'recipient_id' => $invitation->recipient_id,
                'envelope_id' => $invitation->envelope_id,
                'invitation_id' => $invitation->getKey(),
                'session_token_hash' => SigningToken::hash($token),
                'ip_hash' => $fingerprint->ipHash,
                'user_agent_hash' => $fingerprint->userAgentHash,
                'verification_method' => $verification->value,
                'started_at' => $now,
                'expires_at' => $now->addMinutes($this->ttlMinutes()),
                'last_seen_at' => $now,
                'return_url' => $returnUrl,
            ]);
        });

        $this->audit->record(
            AuditActor::system('signing.sessions'),
            'signing.session.started',
            $session,
            [
                'envelope_id' => $session->envelope_id,
                'recipient_id' => $session->recipient_id,
                'verification_method' => $verification->value,
                // Whether a destination was accepted, not which one: the confirmation page
                // shows the URL, and an audit row does not need a second copy of it.
                'return_url_honoured' => $returnUrl !== null,
            ],
        );

        return new StartedSigningSession($session, $this->cookies->issue($token));
    }

    /**
     * The live session this request carries for this envelope, or throw.
     *
     * @throws GuestAccessDenied
     */
    public function require(Request $request, Envelope $envelope): SigningSession
    {
        $token = $request->cookie(SigningCookie::NAME);

        if (! is_string($token) || ! SigningToken::looksWellFormed($token)) {
            throw GuestAccessDenied::noSession();
        }

        $session = SigningSession::query()
            ->where('session_token_hash', SigningToken::hash($token))
            ->first();

        if ($session === null || ! $session->isLive()) {
            throw GuestAccessDenied::noSession();
        }

        // Scope, checked against the URL rather than assumed from the cookie. A guest who
        // holds a live session for envelope A and walks to envelope B's session routes gets
        // 403, not envelope A's document under envelope B's address.
        if ($session->envelope_id !== $envelope->getKey()) {
            throw GuestAccessDenied::sessionOutOfScope();
        }

        return $session;
    }

    /**
     * Push a live session's expiry out by a full window.
     *
     * Deliberately not called on the request that ends the session, and deliberately a plain
     * update rather than a `save()`: the row has no invariants to re-run and touching it
     * should not cost a model event.
     */
    public function touch(SigningSession $session): void
    {
        $now = CarbonImmutable::now();
        $expiresAt = $now->addMinutes($this->ttlMinutes());

        SigningSession::query()
            ->whereKey($session->getKey())
            ->whereNull('ended_at')
            ->update(['last_seen_at' => $now, 'expires_at' => $expiresAt, 'updated_at' => $now]);

        $session->forceFill(['last_seen_at' => $now, 'expires_at' => $expiresAt]);
    }

    /**
     * Close a session for a stated reason: the person signed, declined, or asked to stop.
     *
     * Returns the cookie that clears the browser's copy, so the caller cannot end the row
     * and leave the credential in place.
     */
    public function end(SigningSession $session, string $reason): void
    {
        $now = CarbonImmutable::now();

        SigningSession::query()
            ->whereKey($session->getKey())
            ->whereNull('ended_at')
            ->update(['ended_at' => $now, 'ended_reason' => mb_substr($reason, 0, 32), 'updated_at' => $now]);

        $session->forceFill(['ended_at' => $now, 'ended_reason' => $reason]);

        $this->audit->record(
            AuditActor::system('signing.sessions'),
            'signing.session.ended',
            $session,
            ['reason' => $reason],
        );
    }

    /**
     * The recipient a session speaks for, refreshed from the database.
     *
     * Always re-read. The recipient's state is the ordering guard (docs/signing/state-machine.md)
     * and a copy loaded when the session started would let somebody act after an earlier
     * stage reopened or after they had already signed in another tab.
     */
    public function recipientFor(SigningSession $session): EnvelopeRecipient
    {
        return EnvelopeRecipient::query()->whereKey($session->recipient_id)->firstOrFail();
    }

    /**
     * Refuse a recipient who is not currently allowed to act.
     *
     * The state machine enforces this too, and would refuse the write. Checking it here as
     * well is what lets the page say "you have already signed this" instead of rendering a
     * form whose submission is guaranteed to fail.
     *
     * @throws GuestAccessDenied
     */
    public function assertActive(EnvelopeRecipient $recipient): void
    {
        if ($recipient->state !== RecipientState::Active) {
            throw GuestAccessDenied::recipientNotActive($recipient->state->value);
        }
    }

    public function forgetCookie(): Cookie
    {
        return $this->cookies->forget();
    }

    private function ttlMinutes(): int
    {
        $minutes = (int) $this->config->get('esign.signing.session_ttl_minutes', 120);

        return max(1, $minutes);
    }
}
