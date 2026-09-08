<?php

declare(strict_types=1);

namespace Tests\Feature\Integration\Firma;

use App\Domain\Signing\Models\Envelope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FirmaFacadeScenario;
use Tests\TestCase;

/**
 * The four recorded workflows, replayed against the facade.
 *
 * This is the acceptance test for issue #33. For each directory under
 * `tests/Fixtures/firma/firma-compat-v1/` it drives an envelope through the **real state
 * machine** to the state that fixture captures, then asserts that the facade's four polling
 * responses carry exactly the fixture's key sets — every key, no extras, at every level of
 * nesting — and satisfy the same structural assertions
 * `tests/Feature/Compatibility/FirmaFixtureShapeTest` makes about the fixtures themselves.
 *
 * **Key sets, not values.** The fixtures are sanitized recordings of somebody else's
 * workspace: their ids, names, dates and coordinates are theirs. What the consumer's polling
 * code depends on is that the keys are present, that `status.*` are booleans, that `results`
 * wraps the collections, and that the flat and nested coordinates agree. Asserting values
 * would be asserting the fixtures against themselves.
 *
 * **One key set differs by design, and it is the id.** The fixtures' `id` is a UUID; this
 * application's is its own public ULID. `FirmaFixtureShapeTest` pins the UUID shape *of the
 * fixtures*, and the facade deliberately does not reproduce it — minting a parallel
 * identifier space so a compatibility surface could show UUIDs would mean two ids for one
 * agreement and a mapping table to keep them in step. Recorded in the capability matrix; the
 * shape is asserted below so the difference is a tested fact rather than an accident.
 */
class FirmaContractTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURES = __DIR__.'/../../../Fixtures/firma/firma-compat-v1';

    /**
     * Each recorded workflow, with the scenario method that reaches its state.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function workflows(): array
    {
        return [
            // sent, nobody finished, two recipients
            'platform-nda' => ['platform-nda', 'sent'],
            // sent and finished, two recipients, artifacts published
            'practice-nda' => ['practice-nda', 'completed'],
            // sent then withdrawn
            'order-form' => ['order-form', 'cancelled'],
            // finished, single recipient
            'data-destruction' => ['data-destruction', 'completedSingleSigner'],
        ];
    }

    #[DataProvider('workflows')]
    public function test_the_polling_response_has_the_recorded_key_set(string $workflow, string $state): void
    {
        [$scenario, $envelope, $headers] = $this->scenario($state);

        $body = $this->getJson($this->url($envelope), $headers)->assertOk()->json();
        $fixture = $this->fixture($workflow, 'request');

        $this->assertSameKeys($fixture, $body, 'request.json');
        $this->assertSameKeys($fixture['status'], $body['status'], 'request.json status');
        $this->assertSameKeys($fixture['timestamps'], $body['timestamps'], 'request.json timestamps');
        $this->assertSameKeys($fixture['settings'], $body['settings'], 'request.json settings');
        $this->assertSameKeys($fixture['certificate'], $body['certificate'], 'request.json certificate');

        // FirmaFixtureShapeTest's own assertions, applied to our response.
        foreach (['sent', 'finished', 'cancelled', 'declined', 'expired'] as $flag) {
            $this->assertIsBool($body['status'][$flag], "status.$flag must be boolean");
        }

        // The deprecated 0/1 integers really are integers, not the booleans of `settings`.
        foreach (['use_signing_order', 'allow_download', 'hand_drawn_only'] as $key) {
            $this->assertIsInt($body[$key], "$key must be a deprecated 0/1 integer at the top level");
            $this->assertContains($body[$key], [0, 1]);
            $this->assertIsBool($body['settings'][$key], "settings.$key must be a boolean");
        }

        // The intentional difference: our id is a ULID, not the fixtures' UUID.
        $this->assertSame($envelope->public_id, $body['id']);
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $body['id']);
        $this->assertSame($scenario->workspace->public_id, $body['companies_workspaces_id']);
    }

    #[DataProvider('workflows')]
    public function test_the_users_response_has_the_recorded_key_set(string $workflow, string $state): void
    {
        [, $envelope, $headers] = $this->scenario($state);

        $body = $this->getJson($this->url($envelope, '/users'), $headers)->assertOk()->json();
        $fixture = $this->fixture($workflow, 'users');

        $this->assertSame(['results'], array_keys($body));
        $this->assertNotEmpty($body['results']);

        foreach ($body['results'] as $index => $user) {
            $this->assertSameKeys($fixture['results'][0], $user, "users.json results[$index]");

            foreach (['finished_on', 'order', 'email'] as $key) {
                $this->assertArrayHasKey($key, $user);
            }
        }
    }

    #[DataProvider('workflows')]
    public function test_the_fields_response_has_the_recorded_key_set(string $workflow, string $state): void
    {
        [, $envelope, $headers] = $this->scenario($state);

        $body = $this->getJson($this->url($envelope, '/fields'), $headers)->assertOk()->json();
        $fixture = $this->fixture($workflow, 'fields');

        $this->assertSame(['results'], array_keys($body));
        $this->assertNotEmpty($body['results']);

        foreach ($body['results'] as $index => $field) {
            $this->assertSameKeys($fixture['results'][0], $field, "fields.json results[$index]");
            $this->assertSameKeys($fixture['results'][0]['position'], $field['position'], 'position');

            // FirmaFixtureShapeTest: the legacy flat coordinates and the nested ones agree,
            // typos included. `x_postion` and `heigh` are upstream's spellings (D9).
            $this->assertSame($field['x_postion'], $field['position']['x']);
            $this->assertSame($field['y_position'], $field['position']['y']);
            $this->assertSame($field['width'], $field['position']['width']);
            $this->assertSame($field['heigh'], $field['position']['height']);

            // Percentages of the page, in both spellings.
            foreach (['x_postion', 'y_position', 'width', 'heigh'] as $key) {
                $this->assertGreaterThanOrEqual(0.0, $field[$key], $key);
                $this->assertLessThanOrEqual(100.0, $field[$key], $key);
            }

            $this->assertContains($field['field_type'], ['signature', 'initial', 'text', 'date', 'checkbox']);
            $this->assertSame($field['field_type'], $field['type']);
            $this->assertSame($field['final_value'], $field['value']);
        }
    }

    #[DataProvider('workflows')]
    public function test_the_download_response_has_the_recorded_key_set(string $workflow, string $state): void
    {
        [, $envelope, $headers] = $this->scenario($state);

        $body = $this->getJson($this->url($envelope, '/download'), $headers)->assertOk()->json();
        $fixture = $this->fixture($workflow, 'download');

        $this->assertSameKeys($fixture, $body, 'download.json');

        // FirmaFixtureShapeTest's own assertions.
        $this->assertIsBool($body['is_partial']);
        $this->assertContains($body['status'], ['in_progress', 'finished', 'cancelled', 'declined', 'expired']);
        $this->assertNotNull($body['download_url']);

        // The fixtures' semantics: finished is whole, anything else is partial.
        $this->assertSame($fixture['status'] === 'finished', $body['is_partial'] === false);
    }

    /**
     * Every key of the recording, and no key the recording does not have.
     *
     * Both directions matter. A missing key breaks a consumer that reads it; an extra one is
     * a member of somebody else's contract we invented, and a client generated from their
     * schema would reject it.
     *
     * @param  array<string, mixed>  $fixture
     * @param  array<string, mixed>  $actual
     */
    private function assertSameKeys(array $fixture, array $actual, string $what): void
    {
        $expected = array_keys($fixture);
        $got = array_keys($actual);
        sort($expected);
        sort($got);

        $this->assertSame($expected, $got, sprintf(
            "%s key set differs.\n  missing: %s\n  extra:   %s",
            $what,
            implode(', ', array_diff($expected, $got)) ?: '(none)',
            implode(', ', array_diff($got, $expected)) ?: '(none)',
        ));
    }

    /**
     * @return array{0: FirmaFacadeScenario, 1: Envelope, 2: array<string, string>}
     */
    private function scenario(string $state): array
    {
        $scenario = FirmaFacadeScenario::create();
        $issued = $scenario->credential();

        /** @var Envelope $envelope */
        $envelope = $scenario->{$state}();

        return [$scenario, $envelope, FirmaFacadeScenario::headers($issued)];
    }

    private function url(Envelope $envelope, string $suffix = ''): string
    {
        return '/functions/v1/signing-request-api/signing-requests/'.$envelope->public_id.$suffix;
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $workflow, string $name): array
    {
        return json_decode(
            (string) file_get_contents(self::FIXTURES."/$workflow/$name.json"),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }
}
