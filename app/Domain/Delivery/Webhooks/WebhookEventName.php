<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Webhooks;

use App\Domain\Delivery\Webhooks\Exceptions\UnknownEventNameException;

/**
 * The event names the outbox will accept.
 *
 * Two families, and nothing else. The `signing_request.*` names are the exact
 * strings the `firma-compat-v1` profile documents
 * (docs/compatibility/firma-capability-matrix.md, "Webhook events"); they are
 * reproduced verbatim because a receiver switches on them. Anything we invent
 * is prefixed `esign.`, so a reader can always tell which names came from the
 * upstream contract and which are ours.
 *
 * An unrecognised name is refused rather than recorded. Silently accepting
 * `signing_request.declined` — an event upstream does not have (disagreement
 * D12) — or a typo like `signing_request.compleded` would produce an event no
 * receiver ever handles, which is the "successful no-op" AGENTS.md forbids.
 */
final class WebhookEventName
{
    public const OUR_PREFIX = 'esign.';

    /**
     * The in-profile upstream names, verbatim.
     *
     * Template, workspace, and domain events are deliberately absent: they are
     * out of `firma-compat-v1`.
     *
     * @var list<string>
     */
    public const PROFILE = [
        'signing_request.created',
        'signing_request.sent',
        'signing_request.viewed',
        'signing_request.updated',
        'signing_request.deleted',
        'signing_request.completed',
        'signing_request.certificate.generated',
        'signing_request.cancelled',
        'signing_request.expired',
        'signing_request.reminder.sent',
        'signing_request.recipient.signed',
        'signing_request.recipient.declined',
        'signing_request.recipient.identity_changed',
    ];

    public static function isKnown(string $eventName): bool
    {
        return in_array($eventName, self::PROFILE, true)
            || (str_starts_with($eventName, self::OUR_PREFIX) && strlen($eventName) > strlen(self::OUR_PREFIX));
    }

    /**
     * @throws UnknownEventNameException
     */
    public static function assertKnown(string $eventName): void
    {
        if (! self::isKnown($eventName)) {
            throw new UnknownEventNameException(
                'Unknown webhook event name "'.$eventName.'". Use one of the profile names in '.
                'docs/compatibility/firma-capability-matrix.md, or prefix an event of our own with "'.
                self::OUR_PREFIX.'".'
            );
        }
    }
}
