<?php

declare(strict_types=1);

namespace Tests\Unit\Delivery;

use App\Domain\Delivery\Webhooks\Exceptions\UnknownEventNameException;
use App\Domain\Delivery\Webhooks\WebhookEventName;
use PHPUnit\Framework\TestCase;

final class WebhookEventNameTest extends TestCase
{
    /**
     * The 26 events upstream sends, as its reference v1.40.4 enumerates them on
     * `POST /webhooks` and `PUT /webhooks/{id}` (the list corrected in v1.40.2). Recorded
     * here as interface facts so the catalog audit (issue #170) is a test, not a memory.
     */
    private const UPSTREAM_SENT = [
        'signing_request.created', 'signing_request.sent', 'signing_request.viewed',
        'signing_request.completed', 'signing_request.expired', 'signing_request.cancelled',
        'signing_request.deleted', 'signing_request.updated', 'signing_request.certificate.generated',
        'signing_request.reminder.sent', 'signing_request.recipient.signed',
        'signing_request.recipient.declined', 'signing_request.recipient.identity_changed',
        'template.updated', 'template.used', 'workspace.created', 'workspace.updated',
        'domain.verified', 'domain.verification.failed',
        'organization_seal.created', 'organization_seal.updated', 'organization_seal.deleted',
        'organization_seal.erased', 'signing_request.seal.applied', 'signing_request.seal.paused',
        'signing_request.seal.swapped',
    ];

    /** Names upstream's v1.40.2 correction says it refuses on subscription and never sends. */
    private const UPSTREAM_REFUSED = [
        'signing_request.declined', 'signing_request.voided', 'signing_request.field.completed',
        'signing_request.resent', 'signing_request.credits.purchased',
    ];

    public function test_every_profile_name_is_one_upstream_sends(): void
    {
        $this->assertCount(26, self::UPSTREAM_SENT);

        foreach (WebhookEventName::PROFILE as $eventName) {
            $this->assertContains($eventName, self::UPSTREAM_SENT, $eventName.' is not in the upstream list');
        }
    }

    public function test_every_name_upstream_refuses_is_refused_here(): void
    {
        foreach (self::UPSTREAM_REFUSED as $eventName) {
            $this->assertFalse(WebhookEventName::isKnown($eventName), $eventName.' should be refused');
        }
    }

    public function test_the_profile_names_are_accepted_verbatim(): void
    {
        foreach (WebhookEventName::PROFILE as $eventName) {
            $this->assertTrue(WebhookEventName::isKnown($eventName), $eventName.' should be in profile');
        }
    }

    public function test_our_own_events_are_accepted_behind_their_prefix(): void
    {
        $this->assertTrue(WebhookEventName::isKnown('esign.artifact.published'));
        $this->assertFalse(WebhookEventName::isKnown('esign.'));
    }

    public function test_an_event_upstream_does_not_have_is_refused(): void
    {
        // Disagreement D12: there is no request-level declined event, and we do
        // not manufacture one to fill the gap.
        $this->expectException(UnknownEventNameException::class);

        WebhookEventName::assertKnown('signing_request.declined');
    }

    public function test_an_out_of_profile_family_is_refused(): void
    {
        $this->expectException(UnknownEventNameException::class);

        WebhookEventName::assertKnown('template.updated');
    }
}
