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
use App\Domain\Signing\Sessions\Models\RecipientInvitation;
use App\Domain\Signing\Sessions\OtpChallenges;
use App\Domain\Signing\Sessions\OtpPurpose;
use App\Domain\Signing\Sessions\OtpRequirement;
use App\Domain\Signing\Sessions\ReturnUrlPolicy;
use App\Domain\Signing\Sessions\SigningSessionManager;
use App\Domain\Signing\Sessions\SigningVerification;
use App\Http\Controllers\Controller;
use App\Http\Requests\Signing\StartSigningSessionRequest;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The two ends of the invitation link: the page a mail scanner may safely fetch, and the
 * explicit action that turns a credential into a session.
 *
 * ## Why there is a landing page at all
 *
 * The obvious design — the link in the mail *is* the signing page — cannot be built safely.
 * A recipient's mail provider fetches every URL in a message before the recipient sees it,
 * and so do link expanders, archivers, and the recipient's own browser prefetching the
 * result of a hover. AGENTS.md states the consequence as a rule: "Signing pages never apply
 * signatures, consume one-shot tokens, or advance recipients on GET."
 *
 * So `show()` reads three rows and renders. It does not consume the invitation, create a
 * session, set a cookie, mail anything, or touch the envelope. Fetching it a thousand times
 * leaves the agreement in exactly the state it started in, which `HarmlessGetTest` asserts
 * by doing precisely that.
 *
 * `start()` is the POST behind the Continue button, and it is where everything happens: the
 * credential is spent, the session row is written, and the cookie is issued. A scanner does
 * not press buttons, and CSRF protection means a third-party page cannot press it for
 * somebody.
 *
 * ## The OTP branch
 *
 * When the deployment, workspace, or envelope asks for a mailed code, `start()` serves both
 * halves. The first submission carries no code, so it mails one and re-renders the landing
 * page with a code box; the second carries the code and continues. One endpoint rather than
 * two because the decision — "does this envelope need a code?" — belongs to the server, and
 * a client that chose between two endpoints would be making it.
 *
 * What the code proves is stated honestly in {@see OtpChallenges}: continued access to the
 * same mailbox the link went to. It is not a second factor and does not change the assurance
 * class of the resulting seal.
 */
class SigningLandingController extends Controller
{
    use RendersGuestRefusals;

    public function __construct(
        private readonly InvitationIssuer $invitations,
        private readonly SigningSessionManager $sessions,
        private readonly OtpRequirement $otpRequirement,
        private readonly OtpChallenges $otp,
        private readonly ReturnUrlPolicy $returnUrls,
        private readonly GuestThrottle $throttle,
        private readonly KeyedDigest $digest,
        private readonly Repository $config,
    ) {}

    /**
     * GET /sign/{envelope}/{token}.
     *
     * Renders and nothing else. Every refusal below is a 403 page with the same wording; see
     * {@see RendersGuestRefusals} for why the page is vaguer than the reason.
     */
    public function show(Request $request, Envelope $envelope, string $token): Response
    {
        try {
            $invitation = $this->invitations->resolve($envelope, $token);
            $recipient = $this->recipientOf($invitation);
            $this->assertSignable($envelope, $recipient);
        } catch (GuestAccessDenied $denied) {
            return $this->refuse($denied, $envelope->title);
        }

        return response()->view('signing.landing', $this->landingData($envelope, $recipient, $token, $request));
    }

    /**
     * POST /sign/{envelope}/{token}/start.
     *
     * The only path in this module that consumes an invitation.
     */
    public function start(StartSigningSessionRequest $request, Envelope $envelope, string $token): Response
    {
        try {
            // Counted before the credential is looked up, so a client working through
            // guessed tokens pays for each attempt whether or not any of them exist.
            $this->throttle->hit(
                'session_start_per_ip',
                ClientFingerprint::of($request, $this->digest)->ipHash,
                (int) $this->config->get('esign.signing.start_per_ip_per_hour', 30),
            );

            $invitation = $this->invitations->resolve($envelope, $token);
            $recipient = $this->recipientOf($invitation);
            $this->assertSignable($envelope, $recipient);

            $verification = SigningVerification::Link;

            if ($this->otpRequirement->for($envelope)) {
                $answered = $this->settleOtp($request, $envelope, $recipient, $token);

                if ($answered instanceof Response) {
                    return $answered;
                }

                $verification = SigningVerification::LinkOtp;
            }

            $started = $this->sessions->start(
                $invitation,
                $request,
                $verification,
                $this->invitations,
                $this->returnUrls->sanitize($request->returnCandidate()),
            );
        } catch (GuestRateLimited $limited) {
            return $this->tooManyAttempts($limited, $envelope->title);
        } catch (GuestAccessDenied $denied) {
            return $this->refuse($denied, $envelope->title);
        }

        // 303, not 302: the browser must follow a POST's redirect with a GET, and the page it
        // lands on is the session page rather than a re-submission of this one.
        return redirect()
            ->route('signing.session.show', ['envelope' => $envelope->public_id], 303)
            ->withCookie($started->cookie);
    }

    /**
     * Mail a code, or check the one that was submitted.
     *
     * Returns a `Response` when the caller should be shown the code box, and `null` when the
     * code has been accepted and `start()` should carry on. Two return shapes for one method
     * is not elegant; the alternative was a boolean plus an out-parameter, or duplicating the
     * landing render in the caller.
     *
     * @throws GuestRateLimited
     */
    private function settleOtp(
        StartSigningSessionRequest $request,
        Envelope $envelope,
        EnvelopeRecipient $recipient,
        string $token,
    ): ?Response {
        $code = $request->code();

        if ($code === null) {
            // A resubmission after the code was already accepted must not demand a second
            // one; the challenge stays verified for its own lifetime.
            if ($this->otp->hasRecentVerification($recipient, OtpPurpose::SessionStart)) {
                return null;
            }

            $challenge = $this->otp->issue($recipient, $envelope, OtpPurpose::SessionStart, $request);

            return response()->view('signing.landing', $this->landingData(
                $envelope,
                $recipient,
                $token,
                $request,
                awaitingCode: true,
                notice: 'A code has been sent to the address this invitation was mailed to. '
                    .'It expires at '.$challenge->expires_at->timezone(config('app.timezone'))->format('H:i T').'.',
            ));
        }

        try {
            $this->otp->verify($recipient, OtpPurpose::SessionStart, $code, $request);
        } catch (OtpRejected $rejected) {
            return response()->view('signing.landing', $this->landingData(
                $envelope,
                $recipient,
                $token,
                $request,
                awaitingCode: true,
                error: $rejected->getMessage(),
            ), 422);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function landingData(
        Envelope $envelope,
        EnvelopeRecipient $recipient,
        string $token,
        Request $request,
        bool $awaitingCode = false,
        ?string $notice = null,
        ?string $error = null,
    ): array {
        return [
            'title' => $envelope->title,
            'sender' => $this->senderName($envelope),
            'recipientName' => $recipient->name,
            'startUrl' => route('signing.start', [
                'envelope' => $envelope->public_id,
                'token' => $token,
            ]),
            'expiresAt' => $envelope->expires_at,
            'awaitingCode' => $awaitingCode,
            'otpLength' => (int) $this->config->get('esign.signing.otp.length', 6),
            'notice' => $notice,
            'error' => $error,
            // Carried through the form rather than stored on the GET: a landing page that
            // wrote a return destination somewhere would be a GET with a side effect.
            'returnCandidate' => (string) $request->input('return', ''),
        ];
    }

    /**
     * @throws GuestAccessDenied
     */
    private function recipientOf(RecipientInvitation $invitation): EnvelopeRecipient
    {
        $recipient = $invitation->recipient;

        if (! $recipient instanceof EnvelopeRecipient) {
            // A live invitation whose recipient row is gone is a corrupted state, not a
            // request problem. Refuse rather than guess.
            throw GuestAccessDenied::unknownInvitation();
        }

        return $recipient;
    }

    /**
     * Both halves of "can anybody sign this right now".
     *
     * The state machine enforces both again and would refuse the write. Doing it here as
     * well is what lets the landing page say "you have already signed this" instead of
     * showing a Continue button that leads to a failure.
     *
     * @throws GuestAccessDenied
     */
    private function assertSignable(Envelope $envelope, EnvelopeRecipient $recipient): void
    {
        if (! in_array($envelope->state, [EnvelopeState::Sent, EnvelopeState::InProgress], true)) {
            throw GuestAccessDenied::envelopeNotSignable($envelope->state->value);
        }

        if ($recipient->state !== RecipientState::Active) {
            throw GuestAccessDenied::recipientNotActive($recipient->state->value);
        }
    }

    private function senderName(Envelope $envelope): string
    {
        $name = trim((string) ($envelope->workspace()->first()?->name ?? ''));

        return $name === '' ? (string) config('app.name') : $name;
    }
}
