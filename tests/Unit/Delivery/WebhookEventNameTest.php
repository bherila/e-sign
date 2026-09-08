<?php

declare(strict_types=1);

namespace Tests\Unit\Delivery;

use App\Domain\Delivery\Webhooks\Exceptions\UnknownEventNameException;
use App\Domain\Delivery\Webhooks\WebhookEventName;
use PHPUnit\Framework\TestCase;

final class WebhookEventNameTest extends TestCase
{
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
