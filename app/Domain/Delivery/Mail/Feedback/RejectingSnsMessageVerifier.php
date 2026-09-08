<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Mail\Feedback;

/**
 * The shipped verifier: it rejects every SNS message.
 *
 * TODO(#35): implement real SNS signature verification.
 *
 * SNS signs its messages, and verifying that signature is the only thing separating
 * `POST /webhooks/mail/ses` from an unauthenticated endpoint that lets anyone mark any
 * message bounced. Doing it properly means fetching the signing certificate named by
 * `SigningCertURL`, checking that the URL is an AWS-controlled `sns.<region>.amazonaws.com`
 * host over HTTPS, rebuilding the canonical string-to-sign for the message type, verifying
 * the signature with the certificate's public key, and caching certificates so a webhook
 * storm is not also a certificate-fetch storm. That is precisely what
 * `aws/aws-sns-message-validator` does, and this project does not have it: `aws-sdk-php` is
 * present only transitively, through `league/flysystem-aws-s3-v3`, and does not include the
 * validator.
 *
 * Rather than hand-roll it, or — far worse — accept unsigned input until someone gets round
 * to it, the endpoint fails closed. Every SES message is refused, the SES feedback path is
 * therefore inert, and Brevo remains the working provider-feedback route.
 *
 * To turn SES feedback on: add `aws/aws-sns-message-validator`, implement
 * SnsMessageVerifier over `Aws\Sns\MessageValidator::validate()`, and bind it in
 * DeliveryServiceProvider in place of this class. Nothing else has to change — the topic
 * check, the mapping, and the recorder are already tested behind this gate.
 */
final class RejectingSnsMessageVerifier implements SnsMessageVerifier
{
    public function verify(array $envelope): void
    {
        throw new SnsVerificationException(
            'SNS signature verification is not implemented, so the SES feedback endpoint refuses every message. '.
            'See App\Domain\Delivery\Mail\Feedback\RejectingSnsMessageVerifier and docs/delivery/mail.md.'
        );
    }
}
