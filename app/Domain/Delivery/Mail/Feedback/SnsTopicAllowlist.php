<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Mail\Feedback;

/**
 * The SNS topics whose feedback this deployment will act on.
 *
 * A topic ARN is not a secret. It appears in console URLs, in CloudTrail, and in the body
 * of every message the topic publishes, so this list is not the authentication — the SNS
 * signature is. What it is, is the answer to "whose traffic is this?": one AWS account can
 * publish to many topics, a shared sending domain reaches many deployments, and a webhook
 * URL pasted into the wrong environment is routine. A validly signed message from a topic
 * this deployment was never told about is somebody else's, and acting on it would let any
 * AWS customer move this application's mail rows.
 *
 * Empty is the default and it disables the endpoint rather than opening it: with no topic
 * configured, DeliveryServiceProvider binds RejectingSnsMessageVerifier and every message
 * is refused.
 */
final class SnsTopicAllowlist
{
    /**
     * @param  list<string>  $arns
     */
    public function __construct(private readonly array $arns) {}

    public static function fromConfig(mixed $config): self
    {
        if (is_string($config)) {
            $config = explode(',', $config);
        }

        if (! is_iterable($config)) {
            return new self([]);
        }

        $arns = [];

        foreach ($config as $arn) {
            if (! is_string($arn)) {
                continue;
            }

            $arn = trim($arn);

            if ($arn !== '') {
                $arns[] = $arn;
            }
        }

        return new self(array_values(array_unique($arns)));
    }

    public function isConfigured(): bool
    {
        return $this->arns !== [];
    }

    /**
     * Compared with hash_equals, not because an ARN is a secret but because comparing every
     * candidate at the same cost keeps this from becoming the one timing signal on an
     * endpoint whose whole job is to be uninformative.
     */
    public function allows(mixed $arn): bool
    {
        if (! is_string($arn)) {
            return false;
        }

        $arn = trim($arn);

        if ($arn === '') {
            return false;
        }

        $matched = false;

        foreach ($this->arns as $allowed) {
            $matched = hash_equals($allowed, $arn) || $matched;
        }

        return $matched;
    }
}
