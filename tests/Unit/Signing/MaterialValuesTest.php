<?php

declare(strict_types=1);

namespace Tests\Unit\Signing;

use App\Domain\Preparation\Schema\FieldSchemaDocument;
use App\Domain\Signing\Fields\MaterialValues;
use App\Domain\Signing\Models\EnvelopeFieldValue;
use PHPUnit\Framework\TestCase;
use Tests\Support\SigningFixtures;

/**
 * The digest an acceptance binds to: what it covers, and what must not change it.
 */
class MaterialValuesTest extends TestCase
{
    public function test_signer_specific_values_do_not_change_the_digest(): void
    {
        $schema = $this->schema();

        $withoutSignature = MaterialValues::digest($schema, [
            $this->value('buyer_ack', true),
        ]);

        $withSignature = MaterialValues::digest($schema, [
            $this->value('buyer_ack', true),
            $this->value('buyer_signature', 'a signature mark'),
            $this->value('seller_title', 'Director'),
        ]);

        // Two signers must be able to prove they agreed to the same text even though only
        // one of them has signed it so far.
        $this->assertSame($withoutSignature, $withSignature);
    }

    public function test_a_material_value_changes_the_digest(): void
    {
        $schema = $this->schema();

        $this->assertNotSame(
            MaterialValues::digest($schema, [$this->value('buyer_notes', 'As reviewed')]),
            MaterialValues::digest($schema, [$this->value('buyer_notes', 'As quietly amended')]),
        );
    }

    public function test_the_digest_does_not_depend_on_the_order_values_arrive_in(): void
    {
        $schema = $this->schema();

        $forwards = [
            $this->value('agreement_effective_date', '2026-01-31'),
            $this->value('buyer_ack', true),
            $this->value('buyer_notes', 'As reviewed'),
        ];

        $this->assertSame(
            MaterialValues::digest($schema, $forwards),
            MaterialValues::digest($schema, array_reverse($forwards)),
        );
    }

    public function test_a_value_for_a_field_outside_the_schema_is_ignored(): void
    {
        $schema = $this->schema();

        $this->assertSame(
            MaterialValues::digest($schema, [$this->value('buyer_ack', true)]),
            MaterialValues::digest($schema, [
                $this->value('buyer_ack', true),
                $this->value('a_field_from_another_document', 'smuggled'),
            ]),
        );
    }

    public function test_an_empty_envelope_has_a_well_defined_digest(): void
    {
        $schema = $this->schema();

        $this->assertSame(
            hash('sha256', '{"encoding":"esign.material-values.v1","values":[]}'),
            MaterialValues::digest($schema, []),
        );
    }

    public function test_the_canonical_form_is_the_documented_encoding(): void
    {
        $this->assertSame(
            '{"encoding":"esign.material-values.v1","values":['
            .'{"field":"agreement_effective_date","value":"2026-01-31"},'
            .'{"field":"buyer_ack","value":true}]}',
            MaterialValues::canonicalJson($this->schema(), [
                $this->value('buyer_ack', true),
                $this->value('agreement_effective_date', '2026-01-31'),
            ]),
        );
    }

    private function schema(): FieldSchemaDocument
    {
        return FieldSchemaDocument::fromArray(SigningFixtures::sequentialTwoSigners());
    }

    private function value(string $fieldId, mixed $value): EnvelopeFieldValue
    {
        return new EnvelopeFieldValue([
            'schema_field_id' => $fieldId,
            'value' => $value,
        ]);
    }
}
