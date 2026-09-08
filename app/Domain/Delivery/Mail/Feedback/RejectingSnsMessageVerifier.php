<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Mail\Feedback;

/**
 * The verifier an unconfigured deployment gets: it rejects every SNS message.
 *
 * Real verification now exists (AwsSnsMessageVerifier), and DeliveryServiceProvider binds it
 * whenever `esign.mail.ses.topic_arns` names at least one topic. This class is what is bound
 * when that list is empty, and it is deliberately not a formality.
 *
 * A deployment with no topic configured has told the application nothing about whose
 * feedback it should believe. A valid AWS signature proves only that *some* AWS customer
 * signed the message; without an allowlist there is no answer to "is this our topic?", and
 * accepting on the signature alone would let anyone with an SNS topic mark this
 * deployment's mail delivered, bounced, or complained about. So the endpoint fails closed
 * rather than degrading to signature-only, and it does so by binding a different class,
 * so the refusal is visible in the container rather than buried in a conditional.
 *
 * The response is 503, not 403: the caller has done nothing wrong and cannot fix it, and SNS
 * retries a 503, so a deployment that later configures a topic does not lose the feedback
 * that arrived in the meantime.
 */
final class RejectingSnsMessageVerifier implements SnsMessageVerifier
{
    public function verify(array $envelope): void
    {
        throw new SnsVerificationException(
            'No SNS topic is configured, so the SES feedback endpoint refuses every message. '.
            'Set ESIGN_MAIL_SES_TOPIC_ARNS; see docs/delivery/mail.md.',
            'no_topic_configured',
        );
    }
}
