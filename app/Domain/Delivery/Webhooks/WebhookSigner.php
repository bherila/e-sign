<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Webhooks;

/**
 * The `firma-compat-v1` webhook signature.
 *
 *   signed_payload = ASCII(timestamp) . "." . exact_raw_json_body
 *   signature      = hex(HMAC-SHA256(secret, signed_payload))
 *   X-Firma-Signature: t=<timestamp>,v1=<signature>
 *
 * No bespoke crypto: one `hash_hmac` call (AGENTS.md). The body must be the
 * exact bytes on the wire, which is why the canonical JSON is encoded once when
 * the event is recorded and reused unchanged by every attempt — re-encoding at
 * send time can reorder keys or re-escape a character and produce a signature
 * the receiver cannot reproduce.
 *
 * Rotation: the header carries one `t=` and one `v1=` per live secret, current
 * first. This is the Stripe convention rather than the upstream one, and it is
 * chosen because a receiver that already loops over the `v1=` entries needs no
 * change to accept a rotation, while a receiver that reads only the first `v1=`
 * still verifies, since the current secret is first. For receivers written
 * literally against the upstream guide we additionally emit the documented
 * `X-Firma-Signature-Old` header during the overlap; both are recorded as
 * documented extensions in the capability matrix.
 */
final class WebhookSigner
{
    public const SIGNATURE_HEADER = 'X-Firma-Signature';

    public const PREVIOUS_SIGNATURE_HEADER = 'X-Firma-Signature-Old';

    public function signature(string $secret, int $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /**
     * @param  list<string>  $secrets  Live secrets, current first.
     */
    public function headerValue(array $secrets, int $timestamp, string $body): string
    {
        $entries = array_map(
            fn (string $secret): string => 'v1='.$this->signature($secret, $timestamp, $body),
            $secrets,
        );

        return 't='.$timestamp.','.implode(',', $entries);
    }

    /**
     * The signature headers for one attempt.
     *
     * @param  list<string>  $secrets  Live secrets, current first.
     * @return array<string, string>
     */
    public function headers(array $secrets, int $timestamp, string $body): array
    {
        $headers = [self::SIGNATURE_HEADER => $this->headerValue($secrets, $timestamp, $body)];

        if (count($secrets) > 1) {
            $headers[self::PREVIOUS_SIGNATURE_HEADER] = $this->headerValue(
                array_slice($secrets, 1, 1),
                $timestamp,
                $body,
            );
        }

        return $headers;
    }
}
