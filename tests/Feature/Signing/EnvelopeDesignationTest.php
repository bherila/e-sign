<?php

declare(strict_types=1);

namespace Tests\Feature\Signing;

use App\Domain\Preparation\Schema\RecipientDesignation;
use App\Domain\Signing\Envelopes\EnvelopeState;
use App\Domain\Signing\Exceptions\SendPreconditionsFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SigningFixtures;
use Tests\Support\SigningScenario;
use Tests\TestCase;

/**
 * Approvers and cc recipients (field schema 1.2, issue #169), as far as this stage goes.
 *
 * An envelope can be built from a schema that designates them, and each recipient row records
 * what the schema said. Sending one is refused until the signing side of #169 gives those
 * parties their own semantics — fail closed, rather than treating an approver or a cc
 * recipient as a signer.
 */
class EnvelopeDesignationTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_envelope_records_each_partys_designation(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->draft(['field_schema' => self::schemaWithApproverAndCc()]);

        $designations = $envelope->recipients()->orderBy('id')->get()
            ->mapWithKeys(static fn ($recipient): array => [$recipient->schema_recipient_id => $recipient->designation])
            ->all();

        ksort($designations);

        $this->assertSame([
            'manager' => RecipientDesignation::Approver,
            'records' => RecipientDesignation::Cc,
            'signer' => RecipientDesignation::Signer,
        ], $designations);

        $snapshot = $envelope->recipients()->where('schema_recipient_id', 'manager')->firstOrFail()->identity_snapshot;
        $this->assertSame('approver', $snapshot['designation']);
    }

    public function test_sending_an_envelope_with_an_approver_or_cc_is_refused_for_now(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->draft(['field_schema' => self::schemaWithApproverAndCc()]);

        try {
            $scenario->machine()->send($envelope);
            $this->fail('An envelope with an approver or a cc recipient must not be sent yet.');
        } catch (SendPreconditionsFailed $failure) {
            $codes = array_column($failure->problems, 'code', 'recipient');

            $this->assertSame('designation_not_yet_supported', $codes['manager'] ?? null);
            $this->assertSame('designation_not_yet_supported', $codes['records'] ?? null);
        }

        $this->assertSame(EnvelopeState::Draft, $envelope->refresh()->state);
    }

    /** @return array<string, mixed> */
    private static function schemaWithApproverAndCc(): array
    {
        $schema = SigningFixtures::singleSigner();
        $schema['schema_version'] = '1.2';
        $schema['recipients'][] = ['id' => 'manager', 'name' => 'Synthetic Manager', 'email' => 'manager@example.test', 'designation' => 'approver'];
        $schema['recipients'][] = ['id' => 'records', 'name' => 'Synthetic Records', 'email' => 'records@example.test', 'designation' => 'cc'];
        $schema['signing_order'] = [['signer'], ['manager']];
        $schema['fields'][] = [
            'id' => 'manager_approval',
            'recipient_id' => 'manager',
            'type' => 'approval_checkmark',
            'page' => 1,
            'rect' => ['x' => 300, 'y' => 650, 'width' => 24, 'height' => 24],
            'required' => true,
        ];

        return $schema;
    }
}
