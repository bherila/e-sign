<?php

declare(strict_types=1);

namespace Tests\Feature\Signing;

use App\Domain\Identity\Audit\AuditEvent;
use App\Domain\Signing\Sessions\Exceptions\GuestAccessDenied;
use App\Domain\Signing\Sessions\InvitationIssuer;
use App\Domain\Signing\Sessions\Models\RecipientInvitation;
use App\Domain\Signing\Sessions\SigningToken;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Support\GuestSigningScenario;
use Tests\TestCase;

/**
 * The credential itself: how it is minted, what is stored, and what supersedes it.
 *
 * docs/HANDOFF.md section 8 asks for high-entropy expiring credentials scoped to one
 * recipient and envelope, stored as verifiers rather than as reusable plaintext, with
 * rotation designed explicitly rather than left implicit. Each of those is a test here.
 */
class InvitationIssuanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_plaintext_token_is_returned_once_and_never_stored(): void
    {
        $scenario = GuestSigningScenario::sent();
        $issued = $scenario->invite();
        $token = GuestSigningScenario::tokenOf($issued);

        $this->assertSame(SigningToken::ENCODED_LENGTH, strlen($token));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $token);

        $row = $issued->invitation->refresh();

        $this->assertSame(hash('sha256', $token), $row->token_hash);

        // Not "the column does not contain the token" — no column anywhere does. Serialising
        // the row is the closest thing to a dump a test can take, and `$hidden` keeps the
        // verifier out of even that.
        $this->assertStringNotContainsString($token, json_encode($row->toArray(), JSON_THROW_ON_ERROR));
        $this->assertArrayNotHasKey('token_hash', $row->toArray());
    }

    public function test_the_url_carries_the_token_in_the_path_and_no_query_parameters(): void
    {
        $scenario = GuestSigningScenario::sent();
        $issued = $scenario->invite();

        $parts = parse_url($issued->url);

        $this->assertIsArray($parts);
        $this->assertArrayNotHasKey('query', $parts, 'A signing URL carries no query string.');
        $this->assertArrayNotHasKey('fragment', $parts, 'A token in a fragment never reaches the server.');
        $this->assertStringStartsWith('/sign/'.$scenario->envelope->public_id.'/', (string) $parts['path']);
    }

    public function test_two_invitations_never_share_a_token(): void
    {
        $scenario = GuestSigningScenario::sent();

        $first = GuestSigningScenario::tokenOf($scenario->invite());
        $second = GuestSigningScenario::tokenOf($scenario->invite());

        $this->assertNotSame($first, $second);
    }

    public function test_issuing_again_revokes_the_previous_credential(): void
    {
        $scenario = GuestSigningScenario::sent();
        $recipient = $scenario->recipient();

        $first = $scenario->invite($recipient);
        $second = app(InvitationIssuer::class)->reissue($recipient);

        $this->assertSame('revoked', $first->invitation->refresh()->refusalReason());
        $this->assertNull($second->invitation->refresh()->refusalReason());
        $this->assertSame('reissued', $first->invitation->refresh()->revoked_reason);
    }

    public function test_a_revoked_credential_no_longer_resolves(): void
    {
        $scenario = GuestSigningScenario::sent();
        $issued = $scenario->invite();
        $token = GuestSigningScenario::tokenOf($issued);

        app(InvitationIssuer::class)->revokeAllFor($scenario->recipient(), 'sender_withdrew');

        $this->expectExceptionMessage('no longer usable');
        app(InvitationIssuer::class)->resolve($scenario->envelope, $token);
    }

    public function test_an_expired_credential_no_longer_resolves(): void
    {
        $scenario = GuestSigningScenario::sent();
        $issued = $scenario->invite(ttlHours: 1);
        $token = GuestSigningScenario::tokenOf($issued);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addHours(2));

        try {
            $this->expectExceptionMessage('no longer usable');
            app(InvitationIssuer::class)->resolve($scenario->envelope, $token);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_consumption_is_a_compare_and_swap_so_only_one_caller_wins(): void
    {
        $scenario = GuestSigningScenario::sent();
        $issuer = app(InvitationIssuer::class);
        $invitation = $scenario->invite()->invitation;

        $this->assertTrue($issuer->consume($invitation));
        $this->assertFalse($issuer->consume($invitation->refresh()));
        $this->assertSame('consumed', $invitation->refresh()->refusalReason());
    }

    public function test_a_token_for_one_envelope_does_not_resolve_against_another(): void
    {
        $first = GuestSigningScenario::sent();
        $second = GuestSigningScenario::sent();

        $token = GuestSigningScenario::tokenOf($first->invite());

        $this->expectExceptionMessage('does not match a live invitation');
        app(InvitationIssuer::class)->resolve($second->envelope, $token);
    }

    public function test_a_malformed_token_is_refused_without_a_query(): void
    {
        $scenario = GuestSigningScenario::sent();
        $scenario->invite();

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        try {
            app(InvitationIssuer::class)->resolve($scenario->envelope, 'not-a-token');
            $this->fail('A malformed token should not resolve.');
        } catch (GuestAccessDenied $denied) {
            $this->assertSame('unknown_invitation', $denied->reason);
        }

        $this->assertSame(0, $queries, 'A malformed token must be refused before it becomes a query.');
    }

    public function test_an_absurd_lifetime_is_refused_rather_than_minted(): void
    {
        $scenario = GuestSigningScenario::sent();

        $this->expectException(InvalidArgumentException::class);
        $scenario->invite(ttlHours: InvitationIssuer::MAX_TTL_HOURS + 1);
    }

    public function test_issuance_is_audited_without_the_credential(): void
    {
        $scenario = GuestSigningScenario::sent();
        $issued = $scenario->invite();
        $token = GuestSigningScenario::tokenOf($issued);

        $event = AuditEvent::query()->where('action', 'signing.invitation.issued')->firstOrFail();

        $this->assertSame($scenario->recipient()->public_id, $event->payload['recipient'] ?? null);
        $this->assertStringNotContainsString(
            $token,
            json_encode($event->payload, JSON_THROW_ON_ERROR),
            'An append-only audit table is the last place a live credential should be written.',
        );
    }

    public function test_only_one_live_invitation_exists_per_recipient(): void
    {
        $scenario = GuestSigningScenario::sent();
        $recipient = $scenario->recipient();

        $scenario->invite($recipient);
        $scenario->invite($recipient);
        $scenario->invite($recipient);

        $this->assertSame(3, RecipientInvitation::query()->where('recipient_id', $recipient->getKey())->count());
        $this->assertSame(1, RecipientInvitation::query()
            ->where('recipient_id', $recipient->getKey())
            ->live()
            ->count());
    }
}
