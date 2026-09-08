<?php

declare(strict_types=1);

namespace Tests\Unit\Signing;

use App\Domain\Evidence\Sealing\AssuranceLevel;
use App\Domain\Signing\Envelopes\EnvelopeSourceSnapshot;
use App\Domain\Signing\Envelopes\SigningMode;
use App\Domain\Signing\Exceptions\InvalidEnvelopeSnapshot;
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

    public function test_it_refuses_a_non_positive_expiration(): void
    {
        $this->expectException(InvalidEnvelopeSnapshot::class);

        EnvelopeSourceSnapshot::fromArray($this->snapshot(['expiration_hours' => 0]));
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
