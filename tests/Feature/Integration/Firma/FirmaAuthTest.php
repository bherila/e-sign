<?php

declare(strict_types=1);

namespace Tests\Feature\Integration\Firma;

use App\Domain\Identity\Credentials\Scope;
use App\Domain\Integration\Firma\FirmaProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FirmaFacadeScenario;
use Tests\Support\PdfFixtures;
use Tests\TestCase;

/**
 * Who reaches this surface, and what an unreachable thing looks like.
 *
 * Four separate gates, and a caller has to pass all of them: the key has to authenticate in
 * the syntax the consumer actually sends, the credential has to hold `compat:firma-v1` to be
 * on this surface at all, it has to hold the route's own resource scope, and the resource has
 * to be in its workspace. Each failure is asserted here in the upstream error envelope,
 * because a consumer branches on the status and the `error` token before it reads anything
 * else.
 */
class FirmaAuthTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/functions/v1/signing-request-api';

    /**
     * The whole reason `AuthenticateServiceCredential` accepts a scheme-less header.
     *
     * `docs/HANDOFF.md` section 10: "The authorization header must accept the consumer's raw
     * API key, not require Bearer-only syntax." The consumer sends the key with no prefix at
     * all, so this is the syntax the facade is judged on.
     */
    public function test_the_raw_api_key_authenticates(): void
    {
        $scenario = FirmaFacadeScenario::create();
        $issued = $scenario->credential();
        $envelope = $scenario->sent();

        $this->getJson(self::BASE.'/signing-requests/'.$envelope->public_id, [
            'Authorization' => $issued->secret,
        ])->assertOk()->assertJsonPath('id', $envelope->public_id);
    }

    /** Bearer works too, for native callers and standard tooling. */
    public function test_the_bearer_syntax_authenticates(): void
    {
        $scenario = FirmaFacadeScenario::create();
        $issued = $scenario->credential();
        $envelope = $scenario->sent();

        $this->getJson(
            self::BASE.'/signing-requests/'.$envelope->public_id,
            FirmaFacadeScenario::bearerHeaders($issued),
        )->assertOk();
    }

    public function test_no_key_is_unauthorized_in_the_upstream_error_shape(): void
    {
        $scenario = FirmaFacadeScenario::create();
        $envelope = $scenario->sent();

        $this->getJson(self::BASE.'/signing-requests/'.$envelope->public_id)
            ->assertStatus(401)
            ->assertJsonPath('error', 'unauthorized')
            // The upstream envelope, not the native API's `{"error": {"code": ...}}`.
            ->assertJsonStructure(['error', 'message']);
    }

    /**
     * `compat:firma-v1` admits a credential to this surface and grants nothing else.
     *
     * A credential issued for the native API must not gain a second HTTP surface by
     * accident, which is why the scope exists at all
     * (App\Domain\Identity\Credentials\Scope).
     */
    public function test_a_credential_without_the_profile_scope_is_forbidden(): void
    {
        $scenario = FirmaFacadeScenario::create();
        $issued = $scenario->credential([Scope::EnvelopesRead, Scope::EnvelopesWrite]);
        $envelope = $scenario->sent();

        $this->getJson(
            self::BASE.'/signing-requests/'.$envelope->public_id,
            FirmaFacadeScenario::headers($issued),
        )
            ->assertStatus(403)
            ->assertJsonPath('error', 'forbidden')
            ->assertJsonPath('details.required_scope', FirmaProfile::SCOPE);
    }

    /**
     * The profile scope on its own is not a resource scope.
     *
     * Nothing is implied by anything else. A credential admitted to the facade still has to
     * be granted `envelopes:read` to read an agreement.
     */
    public function test_the_profile_scope_alone_does_not_grant_reads(): void
    {
        $scenario = FirmaFacadeScenario::create();
        $issued = $scenario->credential([Scope::CompatFirmaV1]);
        $envelope = $scenario->sent();

        $this->getJson(
            self::BASE.'/signing-requests/'.$envelope->public_id,
            FirmaFacadeScenario::headers($issued),
        )
            ->assertStatus(403)
            ->assertJsonPath('error', 'forbidden')
            ->assertJsonPath('details.required_scope', 'envelopes:read');
    }

    /** Reading does not grant writing, on this surface either. */
    public function test_a_read_only_credential_cannot_cancel(): void
    {
        $scenario = FirmaFacadeScenario::create();
        $issued = $scenario->credential([Scope::CompatFirmaV1, Scope::EnvelopesRead]);
        $envelope = $scenario->sent();

        $this->postJson(
            self::BASE.'/signing-requests/'.$envelope->public_id.'/cancel',
            [],
            FirmaFacadeScenario::headers($issued),
        )
            ->assertStatus(403)
            ->assertJsonPath('details.required_scope', 'envelopes:write');
    }

    /**
     * `templates:read` gates every create that uses a template, not just one route.
     *
     * Both create routes accept either a `template_id` or an inline `document`, so whether a
     * call reads a template is a property of the body. Naming the scope on `create-and-send`
     * alone left it bypassable: the same credential could build the same agreement from the
     * same template through `POST /signing-requests` and then call `/send`.
     */
    public function test_building_from_a_template_requires_the_template_scope_on_both_routes(): void
    {
        $scenario = FirmaFacadeScenario::create();
        $issued = $scenario->credential([Scope::CompatFirmaV1, Scope::EnvelopesRead, Scope::EnvelopesWrite]);
        $version = $scenario->publishedTemplate();

        $body = [
            'template_id' => $version->template->public_id,
            'name' => 'Synthetic template-based request',
            'recipients' => [['order' => 1, 'first_name' => 'Dana', 'email' => 'dana@buyer.example.test']],
        ];

        foreach ([self::BASE.'/signing-requests', self::BASE.'/signing-requests/create-and-send'] as $url) {
            $this->postJson($url, $body, FirmaFacadeScenario::headers($issued))
                ->assertStatus(403)
                ->assertJsonPath('error', 'forbidden')
                ->assertJsonPath('details.required_scope', 'templates:read');
        }
    }

    /**
     * The same credential can still post its own PDF.
     *
     * Which is why the scope is enforced where the condition is known rather than named on
     * both routes: a caller that only ever uploads documents should not have to hold a
     * template scope it never uses.
     */
    public function test_a_document_only_create_does_not_need_the_template_scope(): void
    {
        $scenario = FirmaFacadeScenario::create();
        $issued = $scenario->credential([Scope::CompatFirmaV1, Scope::EnvelopesRead, Scope::EnvelopesWrite]);

        $this->postJson(self::BASE.'/signing-requests', [
            'name' => 'Synthetic document-based request',
            'document' => base64_encode(PdfFixtures::bytes('single-page-letter')),
            'recipients' => [['order' => 1, 'first_name' => 'Dana', 'email' => 'dana@buyer.example.test']],
            'fields' => [[
                'type' => 'signature',
                'page_number' => 1,
                'position' => ['x' => 10.0, 'y' => 80.0, 'width' => 25.0, 'height' => 4.0],
            ]],
        ], FirmaFacadeScenario::headers($issued))->assertStatus(201);
    }

    /**
     * Another tenant's id is a 404, exactly like one that does not exist.
     *
     * The lookup is constrained by the credential's workspace before the id from the URL is
     * used, so nothing here ever learned which of the two it was — which is the point
     * (`docs/HANDOFF.md` section 10). A 403 would confirm the id exists.
     */
    public function test_another_workspaces_signing_request_is_not_found(): void
    {
        $mine = FirmaFacadeScenario::create();
        $issued = $mine->credential();

        $theirs = FirmaFacadeScenario::create();
        $envelope = $theirs->sent();

        foreach (['', '/users', '/fields', '/download'] as $suffix) {
            $this->getJson(
                self::BASE.'/signing-requests/'.$envelope->public_id.$suffix,
                FirmaFacadeScenario::headers($issued),
            )
                ->assertStatus(404)
                ->assertJsonPath('error', 'not_found');
        }
    }

    /**
     * An id that was never one of ours at all.
     *
     * The recorded fixtures' README notes one id the consumer had stored with an `sr_` prefix
     * that upstream answers 404 for on every endpoint. The facade answers the same, in the
     * same shape, rather than letting a malformed id fall through to the global handler.
     */
    public function test_an_id_that_is_not_ours_is_not_found_on_every_endpoint(): void
    {
        $scenario = FirmaFacadeScenario::create();
        $issued = $scenario->credential();

        foreach (['', '/users', '/fields', '/download'] as $suffix) {
            $this->getJson(
                self::BASE.'/signing-requests/sr_not_a_real_id'.$suffix,
                FirmaFacadeScenario::headers($issued),
            )
                ->assertStatus(404)
                ->assertJsonPath('error', 'not_found');
        }
    }

    /**
     * Out of profile is 501 naming the path, never 404 and never an empty success.
     *
     * `/resend` is a real upstream route. Answering 404 would claim it does not exist;
     * answering 200 would report that a signer had been written to when nobody had.
     */
    public function test_a_route_outside_the_profile_is_not_implemented(): void
    {
        $scenario = FirmaFacadeScenario::create();
        $issued = $scenario->credential();
        $envelope = $scenario->sent();

        $this->postJson(
            self::BASE.'/signing-requests/'.$envelope->public_id.'/resend',
            [],
            FirmaFacadeScenario::headers($issued),
        )
            ->assertStatus(501)
            ->assertJsonPath('error', 'unsupported')
            ->assertJsonPath('details.profile', FirmaProfile::NAME)
            ->assertJsonPath('details.unsupported_option', 'POST /'.ltrim(self::BASE, '/').'/signing-requests/'.$envelope->public_id.'/resend');

        $this->getJson(self::BASE.'/templates', FirmaFacadeScenario::headers($issued))
            ->assertStatus(501)
            ->assertJsonPath('error', 'unsupported');
    }

    /**
     * An expired credential stops working, and says so specifically.
     *
     * The expiry is set in the future and time is moved past it, because the issuer refuses
     * to mint an already-expired credential — a key that authenticates nothing would look
     * like a successful issue.
     */
    public function test_an_expired_credential_is_unauthorized(): void
    {
        $scenario = FirmaFacadeScenario::create();
        $issued = $scenario->credential(expiresAt: now()->addHour());
        $envelope = $scenario->sent();

        $this->travelTo(now()->addDay());

        $this->getJson(
            self::BASE.'/signing-requests/'.$envelope->public_id,
            FirmaFacadeScenario::headers($issued),
        )
            ->assertStatus(401)
            ->assertJsonPath('error', 'unauthorized');
    }
}
