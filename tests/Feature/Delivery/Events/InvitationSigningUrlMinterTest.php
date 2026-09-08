<?php

namespace Tests\Feature\Delivery\Events;

use App\Domain\Delivery\Events\InvitationSigningUrlMinter;
use App\Domain\Delivery\Events\SigningUrlMinter;
use App\Domain\Signing\Sessions\Models\RecipientInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SigningScenario;
use Tests\TestCase;

class InvitationSigningUrlMinterTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_container_binds_the_invitation_backed_minter(): void
    {
        $this->assertInstanceOf(InvitationSigningUrlMinter::class, $this->app->make(SigningUrlMinter::class));
    }

    public function test_minting_issues_a_live_invitation_and_returns_its_landing_url(): void
    {
        $envelope = SigningScenario::create()->bind()->sent();
        $recipient = $envelope->recipients()->orderBy('order_index')->firstOrFail();

        $url = $this->app->make(SigningUrlMinter::class)->signingUrlFor($recipient);

        $this->assertStringStartsWith('http', $url);
        $this->assertStringContainsString('/sign/'.$envelope->public_id.'/', $url);
        $this->assertSame(1, RecipientInvitation::query()->where('recipient_id', $recipient->getKey())->whereNull('revoked_at')->count());
    }

    public function test_minting_again_revokes_the_previous_link(): void
    {
        $envelope = SigningScenario::create()->bind()->sent();
        $recipient = $envelope->recipients()->orderBy('order_index')->firstOrFail();
        $minter = $this->app->make(SigningUrlMinter::class);

        $first = $minter->signingUrlFor($recipient);
        $second = $minter->signingUrlFor($recipient);

        $this->assertNotSame($first, $second);
        $this->assertSame(1, RecipientInvitation::query()->where('recipient_id', $recipient->getKey())->whereNull('revoked_at')->count());
        $this->assertSame(1, RecipientInvitation::query()->where('recipient_id', $recipient->getKey())->whereNotNull('revoked_at')->count());
    }
}
