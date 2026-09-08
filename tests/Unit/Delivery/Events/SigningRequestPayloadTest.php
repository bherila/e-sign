<?php

declare(strict_types=1);

namespace Tests\Unit\Delivery\Events;

use App\Domain\Delivery\Events\SigningRequestPayload;
use App\Domain\Identity\Models\Workspace;
use App\Domain\Signing\Envelopes\EnvelopeState;
use App\Domain\Signing\Envelopes\RecipientState;
use App\Domain\Signing\Models\Envelope;
use App\Domain\Signing\Models\EnvelopeRecipient;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The payload against the captured `firma-compat-v1` fixtures.
 *
 * Key sets, never values. The fixtures are one provider's synthetic responses on one day;
 * the contract we owe a receiver is that the fields it switches on are present and named the
 * way it expects, not that our timestamps match somebody else's. Comparing values would make
 * the test a re-encoding of the fixture and it would still not prove compatibility.
 *
 * No database: the payload builder prefers a loaded relation, so the whole shape is built
 * from models in memory. The application is still booted, because Eloquent resolves date
 * casts through a connection's grammar even for a row that is never saved.
 */
class SigningRequestPayloadTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../../../Fixtures/firma/firma-compat-v1';

    /**
     * The `SigningRequestDetail` fields the consumer reads (capability matrix,
     * "GET /signing-requests/{id}").
     */
    private const REQUEST_FIELDS = [
        'id',
        'name',
        'companies_workspaces_id',
        'status',
        'timestamps',
        'expiration_hours',
        'expires_at',
    ];

    /** The `SigningRequestUser` fields the consumer reads ("GET .../users"). */
    private const RECIPIENT_FIELDS = [
        'id',
        'name',
        'email',
        'designation',
        'order',
        'finished_on',
        'declined_on',
        'decline_reason',
    ];

    /**
     * @return array<string, array{string}>
     */
    public static function scenarios(): array
    {
        return [
            'practice-nda' => ['practice-nda'],
            'platform-nda' => ['platform-nda'],
            'order-form' => ['order-form'],
            'data-destruction' => ['data-destruction'],
        ];
    }

    #[DataProvider('scenarios')]
    public function test_the_request_block_carries_every_field_the_consumer_reads(string $scenario): void
    {
        $fixture = self::fixture($scenario, 'request.json');
        $ours = SigningRequestPayload::signingRequest(self::envelope());

        foreach (self::REQUEST_FIELDS as $field) {
            $this->assertArrayHasKey($field, $fixture, "The fixture no longer carries '{$field}'.");
            $this->assertArrayHasKey($field, $ours, "Our payload omits '{$field}', which the consumer reads.");
        }
    }

    #[DataProvider('scenarios')]
    public function test_the_status_object_has_exactly_the_upstream_boolean_flags(string $scenario): void
    {
        $fixture = self::fixture($scenario, 'request.json');
        $ours = SigningRequestPayload::status(self::envelope());

        $this->assertSame(array_keys($fixture['status']), array_keys($ours));

        foreach ($ours as $flag => $value) {
            $this->assertIsBool($value, "status.{$flag} must be a boolean, not a string enum.");
        }
    }

    #[DataProvider('scenarios')]
    public function test_the_timestamps_object_has_exactly_the_upstream_on_suffixed_keys(string $scenario): void
    {
        $fixture = self::fixture($scenario, 'request.json');

        $this->assertSame(
            array_keys($fixture['timestamps']),
            array_keys(SigningRequestPayload::timestamps(self::envelope())),
        );
    }

    #[DataProvider('scenarios')]
    public function test_a_recipient_block_carries_every_field_the_consumer_reads(string $scenario): void
    {
        $fixture = self::fixture($scenario, 'users.json')['results'][0];
        $ours = SigningRequestPayload::recipient(self::recipient());

        foreach (self::RECIPIENT_FIELDS as $field) {
            $this->assertArrayHasKey($field, $fixture, "The fixture no longer carries '{$field}'.");
            $this->assertArrayHasKey($field, $ours, "Our recipient block omits '{$field}'.");
        }
    }

    public function test_terminal_flags_overlap_the_way_upstream_says_they_can(): void
    {
        $envelope = self::envelope([
            'state' => EnvelopeState::Cancelled,
            'cancelled_at' => CarbonImmutable::parse('2026-06-28T09:00:00Z'),
        ]);

        $status = SigningRequestPayload::status($envelope);

        // "Multiple can be true for terminal states": a cancelled envelope that was sent
        // reports both, and a single-enum projection could not.
        $this->assertTrue($status['sent']);
        $this->assertTrue($status['cancelled']);
        $this->assertFalse($status['finished']);
    }

    public function test_there_is_no_download_hint_until_an_artifact_is_published(): void
    {
        $this->assertNull(SigningRequestPayload::signingRequest(self::envelope())['download']);
    }

    public function test_a_published_artifact_is_reported_without_a_url(): void
    {
        $envelope = self::envelope([
            'state' => EnvelopeState::Completed,
            'artifact_ref' => 'artifacts/01JQZX9K7M4N2P5R8T3V6W1Y0B/final.pdf',
            'completed_at' => CarbonImmutable::parse('2026-06-29T09:00:00Z'),
        ]);

        $download = SigningRequestPayload::signingRequest($envelope)['download'];

        $this->assertIsArray($download);
        $this->assertTrue($download['available']);
        $this->assertFalse($download['is_partial']);

        // A webhook body is stored once and replayed for hours. A link in it would either be
        // dead on arrival or a long-lived credential in a receiver's log.
        $flattened = json_encode($download, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('http', $flattened);
        $this->assertArrayNotHasKey('download_url', $download);
    }

    public function test_the_last_signing_action_is_the_most_recent_recipient_act(): void
    {
        $first = self::recipient([
            'public_id' => '01JQZX9K7M4N2P5R8T3V6W1Y01',
            'state' => RecipientState::Signed,
            'signed_at' => CarbonImmutable::parse('2026-06-27T10:00:00Z'),
        ]);
        $second = self::recipient([
            'public_id' => '01JQZX9K7M4N2P5R8T3V6W1Y02',
            'state' => RecipientState::Signed,
            'signed_at' => CarbonImmutable::parse('2026-06-27T11:30:00Z'),
        ]);

        $envelope = self::envelope(recipients: [$first, $second]);

        $this->assertSame(
            $second->signed_at?->toIso8601String(),
            SigningRequestPayload::timestamps($envelope)['last_signing_action_on'],
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<EnvelopeRecipient>  $recipients
     */
    private static function envelope(array $attributes = [], array $recipients = []): Envelope
    {
        $envelope = new Envelope;
        $envelope->forceFill(array_replace([
            'public_id' => '01JQZX9K7M4N2P5R8T3V6W1Y0B',
            'title' => 'Synthetic mutual NDA',
            'state' => EnvelopeState::Sent,
            'expiration_hours' => 168,
            'created_at' => CarbonImmutable::parse('2026-06-27T08:00:00Z'),
            'updated_at' => CarbonImmutable::parse('2026-06-27T08:30:00Z'),
            'sent_at' => CarbonImmutable::parse('2026-06-27T08:30:00Z'),
            'expires_at' => CarbonImmutable::parse('2026-07-04T08:30:00Z'),
        ], $attributes));

        $workspace = new Workspace;
        $workspace->forceFill(['public_id' => '01JQZX9K7M4N2P5R8T3V6W1Y0W', 'name' => 'Example Holdings']);

        $envelope->setRelation('workspace', $workspace);
        $envelope->setRelation('recipients', new Collection($recipients));

        return $envelope;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function recipient(array $attributes = []): EnvelopeRecipient
    {
        $recipient = new EnvelopeRecipient;
        $recipient->forceFill(array_replace([
            'public_id' => '01JQZX9K7M4N2P5R8T3V6W1Y0R',
            'name' => 'Example Buyer',
            'email' => 'buyer@example.test',
            'order_index' => 1,
            'state' => RecipientState::Active,
        ], $attributes));

        return $recipient;
    }

    /**
     * @return array<string, mixed>
     */
    private static function fixture(string $scenario, string $file): array
    {
        $contents = file_get_contents(self::FIXTURES.'/'.$scenario.'/'.$file);

        self::assertIsString($contents, "Missing fixture {$scenario}/{$file}.");

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
