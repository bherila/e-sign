<?php

declare(strict_types=1);

namespace Tests\Unit\Signing;

use App\Domain\Evidence\Sealing\AssuranceLevel;
use App\Domain\Preparation\Schema\AnchorPlacementMode;
use App\Domain\Preparation\Schema\InvalidFieldSchemaException;
use App\Domain\Preparation\Schema\SchemaVersion;
use App\Domain\Signing\Envelopes\EnvelopeSourceSnapshot;
use App\Domain\Signing\Envelopes\SigningMode;
use App\Domain\Signing\Exceptions\InvalidEnvelopeSnapshot;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\SigningFixtures;

/**
 * The boundary between this module and whatever produced the envelope.
 *
 * The array shape is the contract the templates work (issue #22) implements, so the two
 * modules meet here rather than at each other's classes. Everything it accepts and refuses
 * is therefore API surface.
 */
class EnvelopeSourceSnapshotTest extends TestCase
{
    public function test_it_accepts_the_documented_shape_with_defaults(): void
    {
        $snapshot = EnvelopeSourceSnapshot::fromArray($this->snapshot());

        $this->assertSame('Synthetic mutual NDA', $snapshot->title);
        $this->assertSame(12, $snapshot->documentRevisionId);
        $this->assertSame(AssuranceLevel::PadesBB, $snapshot->assuranceLevel);
        $this->assertSame(SigningMode::Sequential, $snapshot->signingMode);
        $this->assertSame([], $snapshot->renderSettings);
        $this->assertNull($snapshot->sourceTemplateVersionId);
        $this->assertSame(168, $snapshot->expirationHours);
    }

    public function test_the_schema_digest_is_of_the_canonical_form(): void
    {
        $snapshot = EnvelopeSourceSnapshot::fromArray($this->snapshot());

        $this->assertSame(
            hash('sha256', $snapshot->fieldSchema->canonicalJson()),
            $snapshot->fieldSchemaSha256(),
        );
        $this->assertSame($snapshot->fieldSchema->toArray(), $snapshot->canonicalFieldSchema());
    }

    public function test_an_explicit_null_expiration_differs_from_an_absent_one(): void
    {
        $this->assertNull(
            EnvelopeSourceSnapshot::fromArray($this->snapshot(['expiration_hours' => null]))->expirationHours,
        );
        $this->assertSame(
            168,
            EnvelopeSourceSnapshot::fromArray($this->snapshot())->expirationHours,
        );
    }

    /**
     * docs/HANDOFF.md section 9: a requested assurance level that cannot be met is an error,
     * never a silent downgrade — which starts with refusing to read one we do not recognise.
     */
    public function test_an_unrecognised_assurance_level_is_refused_rather_than_downgraded(): void
    {
        $this->expectException(InvalidEnvelopeSnapshot::class);
        $this->expectExceptionMessageMatches('/assurance_level/');

        EnvelopeSourceSnapshot::fromArray($this->snapshot(['assurance_level' => 'pades-b-lta']));
    }

    public function test_it_refuses_a_missing_property(): void
    {
        foreach (['title', 'consent_policy_version', 'document_sha256', 'field_schema'] as $property) {
            $snapshot = $this->snapshot();
            unset($snapshot[$property]);

            try {
                EnvelopeSourceSnapshot::fromArray($snapshot);
                $this->fail('A snapshot without "'.$property.'" must not be accepted.');
            } catch (InvalidEnvelopeSnapshot $e) {
                $this->assertStringContainsString($property, $e->getMessage());
            }
        }
    }

    public function test_it_refuses_a_digest_that_is_not_a_sha256(): void
    {
        try {
            EnvelopeSourceSnapshot::fromArray($this->snapshot(['document_sha256' => 'not-a-digest']));
            $this->fail('Only a sha256 hex digest identifies the bytes being signed.');
        } catch (InvalidEnvelopeSnapshot $e) {
            $this->assertSame('invalid_property', $e->code());
        }
    }

    public function test_it_re_validates_the_field_schema_rather_than_trusting_it(): void
    {
        $schema = SigningFixtures::sequentialTwoSigners();
        // A field pointing at a recipient the document does not declare.
        $schema['fields'][0]['recipient_id'] = 'somebody_else';

        try {
            EnvelopeSourceSnapshot::fromArray($this->snapshot(['field_schema' => $schema]));
            $this->fail('An envelope that carries a schema it cannot read back is unsignable.');
        } catch (InvalidEnvelopeSnapshot $e) {
            $this->assertSame('invalid_field_schema', $e->code());
        }
    }

    /**
     * Validity and availability are two independent questions, and the order between them matters.
     *
     * The boundary answers two different refusals — the schema cannot be read (`invalid_field_schema`)
     * and the schema uses an option this deployment cannot honour yet (`anchor_resolution_unavailable`,
     * carrying its own pointer). Which one a caller gets is decided by an ordering, and an ordering
     * with two independent inputs has four states, not one.
     *
     * Both of the last two review rounds found a defect in exactly those three lines: one fix made
     * the gate's structured refusal survive by moving it outside the catch, which also moved it
     * before validation; the next fix restored the order. Each was correct alone and wrong in
     * interaction, and each was covered by a test of the case that had just broken. This asserts
     * the whole matrix, so either ordering being wrong in either direction fails here — including
     * the direction neither round happened to produce.
     *
     * @return iterable<string, array{bool, bool, string}>
     */
    public static function validityAndAvailability(): iterable
    {
        //        malformed  gated   expected refusal (null = accepted)
        yield 'valid, ungated' => [false, false, ''];
        yield 'valid, gated' => [false, true, 'anchor_resolution_unavailable'];
        yield 'malformed, ungated' => [true, false, 'invalid_field_schema'];
        // The cell the ordering is about: malformed *and* gated. The document stays malformed
        // after resolution ships, so telling the caller to wait for it is an answer to a question
        // they did not ask.
        yield 'malformed, gated' => [true, true, 'invalid_field_schema'];
    }

    #[DataProvider('validityAndAvailability')]
    public function test_the_boundary_answers_validity_before_availability(
        bool $malformed,
        bool $gated,
        string $expected,
    ): void {
        $schema = SigningFixtures::sequentialTwoSigners();

        if ($gated) {
            $schema['schema_version'] = SchemaVersion::CURRENT;
            $schema['fields'][0]['anchor'] = [
                'text' => 'Signature:',
                'occurrence' => 'sole',
                'placement' => AnchorPlacementMode::CrossCheck->value,
                'tolerance' => 2,
            ];
        }

        if ($malformed) {
            // A field pointing at a recipient the document does not declare: invalid whatever
            // this deployment can do, and still invalid after resolution ships.
            $schema['fields'][0]['recipient_id'] = 'somebody_else';
        }

        if ($expected === '') {
            $this->assertInstanceOf(
                EnvelopeSourceSnapshot::class,
                EnvelopeSourceSnapshot::fromArray($this->snapshot(['field_schema' => $schema])),
            );

            return;
        }

        try {
            EnvelopeSourceSnapshot::fromArray($this->snapshot(['field_schema' => $schema]));
            $this->fail('Expected '.$expected.'.');
        } catch (InvalidEnvelopeSnapshot $refused) {
            $this->assertSame($expected, $refused->code());
        } catch (InvalidFieldSchemaException $refused) {
            $this->assertSame(
                $expected,
                $refused->errors()[0]->code->value,
                'The gate\'s refusal must keep its own code and pointer rather than being flattened.',
            );
            $this->assertSame('/fields/0/anchor/placement', $refused->errors()[0]->path);
        }
    }

    /**
     * Refused, not truncated. A shortened title is a silently different agreement name in
     * every notification the recipients receive, and the caller is never told.
     */
    public function test_it_refuses_values_too_long_for_their_columns(): void
    {
        foreach ([
            'title' => str_repeat('a', EnvelopeSourceSnapshot::MAX_TITLE_LENGTH + 1),
            'consent_policy_version' => str_repeat('v', EnvelopeSourceSnapshot::MAX_CONSENT_POLICY_VERSION_LENGTH + 1),
        ] as $property => $value) {
            try {
                EnvelopeSourceSnapshot::fromArray($this->snapshot([$property => $value]));
                $this->fail('An over-long "'.$property.'" must be refused rather than truncated.');
            } catch (InvalidEnvelopeSnapshot $e) {
                $this->assertSame('invalid_property', $e->code());
                $this->assertStringContainsString('character limit', $e->getMessage());
            }
        }
    }

    /**
     * The upper bound is about the column, not the workflow: `expires_at` is a MySQL
     * `TIMESTAMP`, which runs out in 2038, so an unbounded expiry would make `send()` succeed
     * on SQLite and fail on the production engines.
     */
    public function test_it_refuses_an_expiration_outside_the_supported_range(): void
    {
        foreach ([0, -1, EnvelopeSourceSnapshot::MAX_EXPIRATION_HOURS + 1] as $hours) {
            try {
                EnvelopeSourceSnapshot::fromArray($this->snapshot(['expiration_hours' => $hours]));
                $this->fail('Expected '.$hours.' hours to be refused.');
            } catch (InvalidEnvelopeSnapshot $e) {
                $this->assertSame('invalid_property', $e->code());
            }
        }

        $this->assertSame(
            EnvelopeSourceSnapshot::MAX_EXPIRATION_HOURS,
            EnvelopeSourceSnapshot::fromArray($this->snapshot([
                'expiration_hours' => EnvelopeSourceSnapshot::MAX_EXPIRATION_HOURS,
            ]))->expirationHours,
        );
    }

    public function test_it_refuses_a_template_version_id_that_is_not_a_public_identifier(): void
    {
        try {
            EnvelopeSourceSnapshot::fromArray($this->snapshot([
                'source_template_version_id' => str_repeat('x', 27),
            ]));
            $this->fail('The column is a ULID; a longer id would truncate on MySQL and store whole on SQLite.');
        } catch (InvalidEnvelopeSnapshot $e) {
            $this->assertSame('invalid_property', $e->code());
        }

        $ulid = (string) Str::ulid();
        $this->assertSame(
            $ulid,
            EnvelopeSourceSnapshot::fromArray(
                $this->snapshot(['source_template_version_id' => $ulid]),
            )->sourceTemplateVersionId,
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function snapshot(array $overrides = []): array
    {
        return array_replace([
            'title' => 'Synthetic mutual NDA',
            'document_revision_id' => 12,
            'document_sha256' => hash('sha256', 'synthetic review revision'),
            'field_schema' => SigningFixtures::sequentialTwoSigners(),
            'consent_policy_version' => SigningFixtures::CONSENT_VERSION,
        ], $overrides);
    }
}
