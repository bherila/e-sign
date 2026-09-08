<?php

declare(strict_types=1);

namespace App\Http\Controllers\Signing;

use App\Domain\Signing\Envelopes\EnvelopeState;
use App\Domain\Signing\Envelopes\RecipientState;
use App\Domain\Signing\Models\Envelope;
use App\Domain\Signing\Models\EnvelopeRecipient;
use App\Domain\Signing\Sessions\ClientFingerprint;
use App\Domain\Signing\Sessions\Exceptions\GuestAccessDenied;
use App\Domain\Signing\Sessions\Exceptions\GuestRateLimited;
use App\Domain\Signing\Sessions\Exceptions\OtpRejected;
use App\Domain\Signing\Sessions\GuestThrottle;
use App\Domain\Signing\Sessions\InvitationIssuer;
use App\Domain\Signing\Sessions\KeyedDigest;
use App\Domain\Signing\Sessions\OtpChallenges;
use App\Domain\Signing\Sessions\OtpPurpose;
use App\Domain\Signing\Sessions\SigningSessionManager;
use App\Domain\Signing\Sessions\SigningVerification;
use App\Http\Controllers\Controller;
use App\Http\Requests\Signing\ResolveLegacyRecipientRequest;
use App\Http\Requests\Signing\VerifyLegacyRecipientRequest;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /signing/{recipientPublicId}` — the compatibility URL, and the only way a bare
 * recipient identifier ever leads to signing.
 *
 * ## Why this exists at all
 *
 * The provider this application is compatible with addresses signing by recipient id alone:
 * possession of the identifier is the authorization. docs/HANDOFF.md section 8 refuses that
 * outright — "A public recipient UUID alone must not authorize signing … Do not sacrifice
 * authorization to preserve a URL construction shortcut" — but it also names the way out:
 * "A legacy `/signing/{recipientId}` resolver can require mailbox verification."
 *
 * That is exactly what this is. The identifier gets somebody to a form and nothing else. To
 * go further they have to state the address the invitation was sent to and then read a code
 * delivered to it, which is the same mailbox check the invitation link itself represents —
 * reconstructed, because the credential is missing.
 *
 * ## What it refuses to reveal
 *
 * Every response on the way in is identical whether or not the address is right. The page
 * never displays the address on file, never says "we sent a code to a…@example.com", and
 * never confirms a match; it says a code has been sent *if* the address matches, and means
 * the conditional. It does not show the agreement's title or the sender either, because
 * anyone holding the identifier would otherwise learn who is contracting with whom.
 *
 * A wrong code and a code that was never issued produce the same message for the same
 * reason. Both are rate limited per client address, and the code itself is limited per
 * destination address, so the resolver cannot be used to mail somebody repeatedly.
 *
 * ## What success produces
 *
 * A fresh invitation — minted, then immediately exchanged for a session in this same POST.
 * Two things follow from doing it here rather than bouncing through the landing page: the
 * mailbox check that just happened is not asked for a second time, and the credential is
 * never rendered into a page or a redirect, so it exists only inside this request. The
 * session records `link+otp`, because that is what was actually done, and
 * `signing_sessions.invitation_id` keeps the credential chain readable afterwards.
 *
 * Minting an invitation also revokes any that were live, which is the honest consequence: a
 * recipient who resolves this way has proved mailbox access now, and the older link in that
 * mailbox stops working.
 */
class LegacyRecipientResolverController extends Controller
{
    use RendersGuestRefusals;

    public function __construct(
        private readonly OtpChallenges $otp,
        private readonly InvitationIssuer $invitations,
        private readonly SigningSessionManager $sessions,
        private readonly GuestThrottle $throttle,
        private readonly KeyedDigest $digest,
        private readonly Repository $config,
    ) {}

    /**
     * The form. A GET, and it reads two rows and renders — no code is sent, nothing is
     * consumed, nothing is recorded (AGENTS.md, "GET is harmless").
     */
    public function show(EnvelopeRecipient $recipient): Response
    {
        return response()->view('signing.legacy', [
            'emailUrl' => route('signing.legacy.request', ['recipient' => $recipient->public_id]),
            'verifyUrl' => route('signing.legacy.verify', ['recipient' => $recipient->public_id]),
            'awaitingCode' => false,
            'notice' => null,
            'error' => null,
            'otpLength' => $this->otpLength(),
        ]);
    }

    /**
     * Step one: the caller states an address, and a code goes out only if it is the right
     * one.
     */
    public function request(
        ResolveLegacyRecipientRequest $request,
        EnvelopeRecipient $recipient,
    ): Response {
        try {
            $this->throttle->hit(
                'legacy_resolve_per_ip',
                ClientFingerprint::of($request, $this->digest)->ipHash,
                (int) $this->config->get('esign.signing.otp.per_ip_per_hour', 20),
            );

            if ($this->addressMatches($recipient, $request->email()) && $this->isSignable($recipient)) {
                $envelope = $recipient->envelope()->firstOrFail();
                $this->otp->issue($recipient, $envelope, OtpPurpose::LegacyResolve, $request);
            }
        } catch (GuestRateLimited $limited) {
            return $this->tooManyAttempts($limited);
        }

        // The same page, the same wording, and the same status whether a code went out or
        // not. This is the whole reason the branch above is silent.
        return response()->view('signing.legacy', [
            'emailUrl' => route('signing.legacy.request', ['recipient' => $recipient->public_id]),
            'verifyUrl' => route('signing.legacy.verify', ['recipient' => $recipient->public_id]),
            'awaitingCode' => true,
            'notice' => 'If that address matches the one this agreement was sent to, a code is on its way to it.',
            'error' => null,
            'otpLength' => $this->otpLength(),
        ]);
    }

    /** Step two: the code, and — if it is right — a session. */
    public function verify(
        VerifyLegacyRecipientRequest $request,
        EnvelopeRecipient $recipient,
    ): Response {
        try {
            $this->otp->verify($recipient, OtpPurpose::LegacyResolve, $request->code(), $request);

            if (! $this->isSignable($recipient)) {
                throw GuestAccessDenied::recipientNotActive($recipient->state->value);
            }

            $envelope = $recipient->envelope()->firstOrFail();

            // Minted and spent inside this request. The plaintext never reaches a page, a
            // redirect, or a log; it exists to create the row the session points back at.
            $issued = $this->invitations->issue($recipient);
            $started = $this->sessions->start(
                $issued->invitation->refresh(),
                $request,
                SigningVerification::LinkOtp,
                $this->invitations,
            );
        } catch (OtpRejected) {
            // One message for every rejection: a wrong code, an expired one, and one that was
            // never issued because the address did not match must be indistinguishable.
            return response()->view('signing.legacy', [
                'emailUrl' => route('signing.legacy.request', ['recipient' => $recipient->public_id]),
                'verifyUrl' => route('signing.legacy.verify', ['recipient' => $recipient->public_id]),
                'awaitingCode' => true,
                'notice' => null,
                'error' => 'That code is not correct, or it has expired. Ask for another one.',
                'otpLength' => $this->otpLength(),
            ], 422);
        } catch (GuestRateLimited $limited) {
            return $this->tooManyAttempts($limited);
        } catch (GuestAccessDenied $denied) {
            return $this->refuse($denied);
        }

        return redirect()
            ->route('signing.session.show', ['envelope' => $envelope->public_id], 303)
            ->withCookie($started->cookie);
    }

    /**
     * Constant-time comparison of the stated address against the one on file.
     *
     * `hash_equals` over the lower-cased strings rather than `===`. The timing difference on
     * a string comparison is small and this endpoint is rate limited, but the comparison is
     * the entire gate and there is no reason to leave the easy version in place.
     */
    private function addressMatches(EnvelopeRecipient $recipient, string $stated): bool
    {
        return hash_equals(mb_strtolower(trim($recipient->email)), $stated);
    }

    /** Whether anybody could sign as this recipient right now. */
    private function isSignable(EnvelopeRecipient $recipient): bool
    {
        $envelope = $recipient->envelope()->first();

        return $envelope instanceof Envelope
            && in_array($envelope->state, [EnvelopeState::Sent, EnvelopeState::InProgress], true)
            && $recipient->state === RecipientState::Active;
    }

    private function otpLength(): int
    {
        return (int) $this->config->get('esign.signing.otp.length', 6);
    }
}
