<?php

declare(strict_types=1);

namespace App\Domain\Signing\Sessions;

use App\Domain\Delivery\Mail\MailContext;
use App\Domain\Delivery\Mail\MailKind;
use App\Domain\Delivery\Mail\MailOutbox;
use App\Domain\Delivery\Mail\MailRecipient;
use App\Domain\Identity\Audit\AuditActor;
use App\Domain\Identity\Audit\AuditRecorder;
use App\Domain\Signing\Models\Envelope;
use App\Domain\Signing\Models\EnvelopeRecipient;
use App\Domain\Signing\Sessions\Exceptions\GuestRateLimited;
use App\Domain\Signing\Sessions\Exceptions\OtpRejected;
use App\Domain\Signing\Sessions\Models\SigningOtpChallenge;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use SensitiveParameter;

/**
 * Issues and checks the six-digit code some deployments require on top of the link.
 *
 * ## What a mailbox code is worth
 *
 * The invitation already went to this address, so a code sent to the same address
 * demonstrates *continued* access to the same mailbox. It is a second check on one factor,
 * not a second factor, and docs/HANDOFF.md section 9 is explicit that email OTP plus an
 * organizational seal is not an eIDAS advanced or qualified human signature. The evidence
 * model records it as `email_otp` and grades nothing.
 *
 * Where it does help is the case it was added for: a forwarded invitation. Somebody who was
 * sent the mail by the recipient can follow the link; they cannot receive the code, because
 * the code goes to the address on the envelope and never to the address that asked for it.
 *
 * ## Why the code is stored, and what that costs
 *
 * A code has to survive between two HTTP requests, so it is stored — as a *keyed* digest,
 * because six digits is a twenty-bit space and an unkeyed hash of a leaked table is
 * reversible in milliseconds ({@see KeyedDigest}).
 *
 * It is also, unavoidably, in `outbound_mails.context` until that row is pruned: the
 * transactional outbox renders from a persisted context, which is what makes delivery
 * recoverable after a crash (docs/delivery/mail.md). An operator with database access can
 * therefore read a live code. That does not make the check pointless — it is aimed at
 * somebody holding a forwarded link, not at the host administrator, and docs/HANDOFF.md
 * section 8 already requires saying plainly that a compromised host is outside what this
 * evidence model can defend against. docs/signing/guest-access.md says it there too.
 *
 * ## Limits
 *
 * Three ceilings, all through Laravel's `RateLimiter`, all keyed on digests rather than on
 * the address or the client IP itself:
 *
 * - issuance per destination address, so a mailbox cannot be used as a bullhorn;
 * - issuance per client address, so one prober cannot walk a list of recipients;
 * - verification per client address, on top of the per-challenge attempt count.
 *
 * The per-challenge count is the one that stops guessing: it lives on the row, so parallel
 * requests share it, and reaching it burns the challenge rather than merely pausing it.
 */
final class OtpChallenges
{
    public function __construct(
        private readonly Repository $config,
        private readonly KeyedDigest $digest,
        private readonly MailOutbox $outbox,
        private readonly AuditRecorder $audit,
        private readonly GuestThrottle $throttle,
    ) {}

    /**
     * Mail a fresh code, burning any that were outstanding for the same recipient and
     * purpose.
     *
     * Burning first is deliberate: two live codes for one prompt doubles the guessing
     * surface and makes "the code I was just sent" ambiguous to the person reading their
     * inbox.
     *
     * @throws GuestRateLimited
     */
    public function issue(
        EnvelopeRecipient $recipient,
        Envelope $envelope,
        OtpPurpose $purpose,
        Request $request,
    ): SigningOtpChallenge {
        $fingerprint = ClientFingerprint::of($request, $this->digest);

        $this->throttle->hit(
            'otp_issue_per_address',
            $this->digest->of('signing.otp.address', mb_strtolower($recipient->email)),
            (int) $this->config->get('esign.signing.otp.per_address_per_hour', 5),
        );

        $this->throttle->hit(
            'otp_issue_per_ip',
            $fingerprint->ipHash,
            (int) $this->config->get('esign.signing.otp.per_ip_per_hour', 20),
        );

        $now = CarbonImmutable::now();

        SigningOtpChallenge::query()
            ->where('recipient_id', $recipient->getKey())
            ->where('purpose', $purpose->value)
            ->whereNull('burned_at')
            ->whereNull('verified_at')
            ->update(['burned_at' => $now, 'updated_at' => $now]);

        $code = $this->generateCode();

        $challenge = SigningOtpChallenge::query()->create([
            'recipient_id' => $recipient->getKey(),
            'envelope_id' => $envelope->getKey(),
            'purpose' => $purpose->value,
            'code_hash' => $this->hash($code),
            'expires_at' => $now->addMinutes($this->ttlMinutes()),
            'ip_hash' => $fingerprint->ipHash,
        ]);

        $this->outbox->enqueue(
            MailKind::Otp,
            new MailRecipient($recipient->email, $recipient->name),
            new MailContext(
                recipientName: $recipient->name,
                senderName: $this->senderName($envelope),
                agreementTitle: $envelope->title,
                expiresAt: $challenge->expires_at,
                otpCode: $code,
            ),
            $envelope->workspace()->first(),
            $recipient,
        );

        // The code is not in this payload and must never be. An append-only audit table is
        // the last place a live credential should be written.
        $this->audit->record(
            AuditActor::system('signing.otp'),
            'signing.otp.issued',
            $challenge,
            [
                'recipient' => $recipient->public_id,
                'envelope' => $envelope->public_id,
                'purpose' => $purpose->value,
                'expires_at' => $challenge->expires_at->toIso8601String(),
            ],
        );

        return $challenge;
    }

    /**
     * Check a code against the outstanding challenge for this recipient and purpose.
     *
     * Returns the verified challenge. Every failure throws {@see OtpRejected}, and a wrong
     * code costs an attempt whether or not a challenge is outstanding — the per-IP limiter
     * is hit before anything is looked up, so an attacker cannot probe for free by aiming at
     * recipients with no challenge.
     *
     * @throws OtpRejected|GuestRateLimited
     */
    public function verify(
        EnvelopeRecipient $recipient,
        OtpPurpose $purpose,
        #[SensitiveParameter] string $code,
        Request $request,
    ): SigningOtpChallenge {
        $fingerprint = ClientFingerprint::of($request, $this->digest);

        $this->throttle->hit(
            'otp_verify_per_ip',
            $fingerprint->ipHash,
            (int) $this->config->get('esign.signing.otp.verify_per_ip_per_hour', 30),
        );

        $now = CarbonImmutable::now();

        $challenge = SigningOtpChallenge::query()
            ->where('recipient_id', $recipient->getKey())
            ->where('purpose', $purpose->value)
            ->whereNull('burned_at')
            ->whereNull('verified_at')
            ->orderByDesc('id')
            ->first();

        if ($challenge === null) {
            throw OtpRejected::noOpenChallenge();
        }

        if ($challenge->expires_at->lessThanOrEqualTo($now)) {
            $challenge->forceFill(['burned_at' => $now])->save();

            throw OtpRejected::expired();
        }

        $maxAttempts = max(1, (int) $this->config->get('esign.signing.otp.max_attempts', 5));

        // Counted before the comparison, so a request that dies mid-flight still costs an
        // attempt. `increment()` is one statement, which is what makes parallel guesses
        // share the budget instead of each reading the same stale count.
        $challenge->increment('attempts');
        $challenge->refresh();

        if (! hash_equals($challenge->code_hash, $this->hash($code))) {
            $remaining = max(0, $maxAttempts - $challenge->attempts);

            if ($remaining === 0) {
                $challenge->forceFill(['burned_at' => $now])->save();

                $this->audit->record(
                    AuditActor::system('signing.otp'),
                    'signing.otp.exhausted',
                    $challenge,
                    ['recipient' => $recipient->public_id, 'purpose' => $purpose->value],
                );

                throw OtpRejected::exhausted();
            }

            throw OtpRejected::wrongCode($remaining);
        }

        $challenge->forceFill(['verified_at' => $now])->save();

        $this->audit->record(
            AuditActor::system('signing.otp'),
            'signing.otp.verified',
            $challenge,
            ['recipient' => $recipient->public_id, 'purpose' => $purpose->value],
        );

        return $challenge;
    }

    /**
     * Whether this recipient already answered a code for this purpose recently enough to
     * count.
     *
     * Used by the session-start POST, which asks for the code and the Continue action in the
     * same submission; a resubmission after a validation failure elsewhere on the form must
     * not demand a second code.
     */
    public function hasRecentVerification(EnvelopeRecipient $recipient, OtpPurpose $purpose): bool
    {
        return SigningOtpChallenge::query()
            ->where('recipient_id', $recipient->getKey())
            ->where('purpose', $purpose->value)
            ->whereNotNull('verified_at')
            ->where('verified_at', '>', CarbonImmutable::now()->subMinutes($this->ttlMinutes()))
            ->exists();
    }

    /**
     * A uniformly distributed decimal code of the configured length.
     *
     * `random_int` rather than `rand`: this is a credential, and the modulo bias of the
     * obvious alternative is small but entirely avoidable.
     */
    private function generateCode(): string
    {
        $length = max(4, min(10, (int) $this->config->get('esign.signing.otp.length', 6)));

        return str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
    }

    private function hash(#[SensitiveParameter] string $code): string
    {
        return $this->digest->of('signing.otp.code', $code);
    }

    private function ttlMinutes(): int
    {
        return max(1, (int) $this->config->get('esign.signing.otp.ttl_minutes', 10));
    }

    private function senderName(Envelope $envelope): string
    {
        $workspace = $envelope->workspace()->first();
        $name = trim((string) ($workspace?->name ?? ''));

        return $name === '' ? (string) config('app.name') : $name;
    }
}
