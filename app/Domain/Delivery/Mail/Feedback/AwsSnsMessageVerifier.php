<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Mail\Feedback;

use Aws\Sns\Exception\InvalidSnsMessageException;
use Aws\Sns\Message;
use Aws\Sns\MessageValidator;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Throwable;

/**
 * Real SNS signature verification, over `aws/aws-php-sns-message-validator`.
 *
 * **Why the library and not `openssl_verify` here.** AGENTS.md forbids bespoke crypto where
 * a maintained library exists, and this is exactly that case. The delicate part of SNS
 * verification is not the RSA call, it is the canonical string-to-sign: an ordered list of
 * fields that differs between `Notification`, `SubscriptionConfirmation`, and
 * `UnsubscribeConfirmation`, with each present field emitted as `name\nvalue\n` and each
 * absent one skipped. Getting the order or the presence rule wrong does not fail loudly — it
 * fails on the subset of real messages that carry a `Subject`, months later, in production.
 * AWS publishes that list; this class uses AWS's copy of it.
 *
 * The package is 3 files and ~300 lines, Apache-2.0, and adds no transitive dependency: it
 * needs `ext-openssl` and `psr/http-message`, both already present, and `aws/aws-sdk-php` was
 * already in the tree through `league/flysystem-aws-s3-v3`.
 *
 * **What this class adds on top of it**, because the library does none of these:
 *
 *  - **A topic allowlist.** A valid AWS signature proves the message came from SNS, not that
 *    it came from *your* topic. Any AWS customer can sign a message. Checked first and
 *    checked again in SesWebhookRequest, so neither layer is load-bearing alone.
 *  - **SignatureVersion policy.** The library accepts 1 (SHA-1) and 2 (SHA-256) equally.
 *    SHA-1 is refused unless an operator turns it on, because a signature over a hash with
 *    practical collisions is not evidence.
 *  - **A replay window.** An SNS signature never expires, so a captured `Bounce` can be
 *    replayed for as long as the certificate is valid. `Timestamp` must be inside a
 *    configurable window either side of now, in both directions — a future-dated message is
 *    as wrong as an ancient one.
 *  - **A certificate fetch that goes through DestinationPolicy** (SnsSigningCertificates),
 *    instead of the library's default `file_get_contents($certUrl)`, which would be an SSRF
 *    primitive reachable from an unauthenticated endpoint and a fetch amplifier besides.
 *
 * **Host pattern.** The library's default admits every AWS partition, and so does this one:
 * `sns.<region>.amazonaws.com` for commercial and GovCloud (`sns.us-gov-west-1.amazonaws.com`
 * is that shape), and `sns.<region>.amazonaws.com.cn` for China. It is restated here rather
 * than inherited so that a library default changing does not silently change what this
 * application trusts. Nothing else is accepted, and the pattern is anchored at both ends, so
 * `sns.us-east-1.amazonaws.com.attacker.test` and an S3 bucket on `amazonaws.com` are both
 * out. The `.pem` suffix and the HTTPS scheme are the library's own checks, and both run
 * *before* the certificate client is called, so a hostile URL costs no request.
 */
final class AwsSnsMessageVerifier implements SnsMessageVerifier
{
    /**
     * @see MessageValidator the default this deliberately mirrors.
     */
    private const HOST_PATTERN = '/^sns\.[a-zA-Z0-9\-]{3,}\.amazonaws\.com(\.cn)?$/';

    private const SUPPORTED_SIGNATURE_VERSIONS = ['1', '2'];

    private readonly MessageValidator $validator;

    public function __construct(
        private readonly SnsTopicAllowlist $topics,
        SnsSigningCertificates $certificates,
        private readonly SesFeedbackSettings $settings,
    ) {
        $this->validator = new MessageValidator(
            static fn (string $certUrl): string => $certificates->fetch($certUrl),
            self::HOST_PATTERN,
        );
    }

    public function verify(array $envelope): void
    {
        // Cheapest and most decisive first. Everything below costs a certificate fetch or a
        // public-key operation; none of it should run for somebody else's traffic.
        if (! $this->topics->isConfigured()) {
            throw new SnsVerificationException(
                'No SNS topic is configured, so the SES feedback endpoint accepts nothing.',
                'no_topic_configured',
            );
        }

        if (! $this->topics->allows($envelope['TopicArn'] ?? null)) {
            throw new SnsVerificationException(
                'The SNS message names a topic this deployment does not accept.',
                'topic_not_allowlisted',
            );
        }

        $this->assertSignatureVersionIsAcceptable($envelope['SignatureVersion'] ?? null);
        $this->assertWithinReplayWindow($envelope['Timestamp'] ?? null);
        $this->assertCertificateUrlIsAws($envelope['SigningCertURL'] ?? null);

        try {
            $this->validator->validate(new Message($this->signableEnvelope($envelope)));
        } catch (InvalidSnsMessageException) {
            // Covers a bad signature, an unusable certificate, and a certificate URL the
            // library refused. One reason token, because the caller is told nothing either
            // way and an operator reading the row wants "the signature did not check out".
            throw new SnsVerificationException(
                'The SNS message signature did not verify.',
                'signature_invalid',
            );
        } catch (InvalidArgumentException) {
            // Message's own constructor: a field the string-to-sign needs is missing. The
            // Form Request requires all of them, so this is a shape the rules do not yet
            // cover rather than an attack, and it is still a refusal.
            throw new SnsVerificationException(
                'The SNS envelope is missing a field required to verify it.',
                'envelope_incomplete',
            );
        }
    }

    /**
     * The envelope with nulls removed.
     *
     * `Aws\Sns\Message` and `getStringToSign()` both test presence with `isset()`, and
     * FormRequest::validated() can carry an explicit `null` for a nullable field the caller
     * sent as `null`. A null `Subject` must be *absent* from the string-to-sign, not present
     * and empty, because SNS omits the field entirely when there is no subject — and an
     * empty `Subject\n\n` in the signed string is a signature that never verifies.
     *
     * @param  array<string, mixed>  $envelope
     * @return array<string, mixed>
     */
    private function signableEnvelope(array $envelope): array
    {
        return array_filter($envelope, static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @throws SnsVerificationException
     */
    private function assertSignatureVersionIsAcceptable(mixed $version): void
    {
        $version = is_scalar($version) ? trim((string) $version) : '';

        if (! in_array($version, self::SUPPORTED_SIGNATURE_VERSIONS, true)) {
            throw new SnsVerificationException(
                'The SNS message uses an unsupported SignatureVersion.',
                'signature_version_unsupported',
            );
        }

        if ($version === '1' && ! $this->settings->allowSignatureVersion1) {
            throw new SnsVerificationException(
                'SignatureVersion 1 is SHA-1 and is not accepted by this deployment. '
                .'Set the topic\'s SignatureVersion to 2 in SNS.',
                'signature_version_1_refused',
            );
        }
    }

    /**
     * The same shape check the library performs, restated here for two reasons that are not
     * about cryptography.
     *
     * First, a distinct refusal reason: an operator reading `certificate_url_not_aws` in the
     * event log is looking at a forged message, while `signature_invalid` reads like a
     * configuration problem, and the library collapses both into one exception. Second, it
     * makes "a hostile SigningCertURL costs no outbound request" an assertion this codebase
     * owns, rather than a property of a dependency's internal ordering.
     *
     * @throws SnsVerificationException
     */
    private function assertCertificateUrlIsAws(mixed $url): void
    {
        $url = is_string($url) ? trim($url) : '';
        $parts = $url === '' ? false : parse_url($url);

        $acceptable = is_array($parts)
            && ($parts['scheme'] ?? '') === 'https'
            && is_string($parts['host'] ?? null)
            && preg_match(self::HOST_PATTERN, $parts['host']) === 1
            && str_ends_with(strtolower($parts['path'] ?? ''), '.pem');

        if (! $acceptable) {
            throw new SnsVerificationException(
                'The SNS SigningCertURL does not name an AWS SNS signing certificate.',
                'certificate_url_not_aws',
            );
        }
    }

    /**
     * @throws SnsVerificationException
     */
    private function assertWithinReplayWindow(mixed $timestamp): void
    {
        if (! is_string($timestamp) || trim($timestamp) === '') {
            throw new SnsVerificationException(
                'The SNS message carries no Timestamp, so its age cannot be checked.',
                'timestamp_missing',
            );
        }

        try {
            $sentAt = Carbon::parse(trim($timestamp));
        } catch (Throwable) {
            throw new SnsVerificationException(
                'The SNS message Timestamp could not be read.',
                'timestamp_unreadable',
            );
        }

        // Both directions. A future-dated message is not a clock problem to be tolerated;
        // it is either a broken sender or a replay with an edited envelope, and neither is
        // something to act on.
        if (abs(Carbon::now()->diffInSeconds($sentAt, absolute: false)) > $this->settings->replayWindowSeconds) {
            throw new SnsVerificationException(
                'The SNS message is outside the accepted replay window.',
                'timestamp_outside_replay_window',
            );
        }
    }
}
