<?php

declare(strict_types=1);

namespace Tests\Unit\Preparation\Schema;

use App\Domain\Preparation\Schema\AnchorPlacement;
use App\Domain\Preparation\Schema\CoordinateSpaceDeclaration;
use App\Domain\Preparation\Schema\FieldSchemaDocument;
use App\Domain\Preparation\Schema\FieldType;
use App\Domain\Preparation\Schema\InvalidFieldSchemaException;
use App\Domain\Preparation\Schema\Prefill;
use App\Domain\Preparation\Schema\ValidationCode;
use App\Domain\Preparation\Text\AnchorOrigin;
use PHPUnit\Framework\TestCase;
use Tests\Support\FieldSchemaFixture;

/**
 * Import, export, and the round-trip guarantees of the native field document.
 */
class FieldSchemaDocumentTest extends TestCase
{
    public function test_it_imports_the_shared_fixture(): void
    {
        $document = FieldSchemaDocument::fromJson(FieldSchemaFixture::json());

        // The fixture uses `anchor.required`, a 1.1 member, so it declares 1.1 — and the
        // importer keeps whatever version the document arrived with.
        $this->assertSame('1.1', $document->schemaVersion->toString());
        $this->assertSame('doc_synthetic_nda', $document->documentId);
        $this->assertTrue($document->coordinateSpace->isNative());
        $this->assertSame(['buyer', 'counterparty'], $document->recipientIds());
        $this->assertSame([['buyer'], ['counterparty']], $document->signingOrder);
        $this->assertCount(10, $document->fields);
        $this->assertSame(2, $document->highestPage());
    }

    public function test_the_canonical_fixture_round_trips_key_for_key(): void
    {
        $canonical = FieldSchemaFixture::asArray();

        $this->assertSame($canonical, FieldSchemaDocument::fromArray($canonical)->toArray());
    }

    public function test_canonical_json_reproduces_the_fixture_bytes(): void
    {
        $document = FieldSchemaDocument::fromJson(FieldSchemaFixture::json());

        // The fixture is pretty-printed for review; canonical form is compact. Comparing against
        // the fixture re-encoded with the canonical flags pins property order and every value.
        $expected = json_encode(FieldSchemaFixture::asArray(), FieldSchemaDocument::JSON_FLAGS);

        $this->assertSame($expected, $document->canonicalJson());
    }

    public function test_canonical_json_is_idempotent(): void
    {
        $once = FieldSchemaDocument::fromJson(FieldSchemaFixture::json())->canonicalJson();
        $twice = FieldSchemaDocument::fromJson($once)->canonicalJson();

        $this->assertSame($once, $twice);
    }

    public function test_it_preserves_stable_ids_and_template_aliases(): void
    {
        $document = FieldSchemaDocument::fromJson(FieldSchemaFixture::json())->canonicalJson();
        $reimported = FieldSchemaDocument::fromJson($document);

        $field = $reimported->fieldByAlias('counterparty_signature_block');

        $this->assertNotNull($field);
        $this->assertSame('counterparty_signature', $field->id);
        $this->assertSame(FieldType::Signature, $field->type);
        $this->assertNull($reimported->fieldByAlias('never_declared'));
    }

    public function test_optional_properties_survive_the_round_trip(): void
    {
        $document = FieldSchemaDocument::fromJson(FieldSchemaFixture::json());

        $prefilled = $document->field('buyer_printed_name');
        $this->assertNotNull($prefilled);
        $this->assertInstanceOf(Prefill::class, $prefilled->prefill);
        $this->assertSame('recipient.name', $prefilled->prefill->variable);
        $this->assertTrue($prefilled->readOnly);
        $this->assertSame('Buyer printed name', $prefilled->label);

        $anchored = $document->field('counterparty_signature');
        $this->assertNotNull($anchored);
        $this->assertInstanceOf(AnchorPlacement::class, $anchored->anchor);
        $this->assertSame('Counterparty signature:', $anchored->anchor->text);
        $this->assertTrue($anchored->anchor->occurrence->isSole());
        $this->assertSame(AnchorOrigin::BottomLeft, $anchored->anchor->origin);
        $this->assertNotNull($anchored->anchor->offset);
        $this->assertSame(0.0, $anchored->anchor->offset->dx);
        $this->assertSame(12.5, $anchored->anchor->offset->dy);

        $withoutOffset = $document->field('counterparty_notes');
        $this->assertNotNull($withoutOffset);
        $this->assertInstanceOf(AnchorPlacement::class, $withoutOffset->anchor);
        $this->assertNull($withoutOffset->anchor->offset);
        $this->assertNull($withoutOffset->anchor->origin, 'An absent origin stays absent, so the round trip is lossless.');
        $this->assertSame(AnchorOrigin::TopLeft, $withoutOffset->anchor->originCorner(), 'The documented default applies when it is read.');
        $this->assertTrue($withoutOffset->anchor->occurrence->isIndexed());
        $this->assertSame(2, $withoutOffset->anchor->occurrence->index);
    }

    public function test_recipient_and_field_lookups(): void
    {
        $document = FieldSchemaDocument::fromJson(FieldSchemaFixture::json());

        $buyer = $document->recipient('buyer');
        $this->assertNotNull($buyer);
        $this->assertSame('Example Buyer', $buyer->name);
        $this->assertSame('Buyer', $buyer->role);
        $this->assertNull($document->recipient('witness'));

        $this->assertCount(5, $document->fieldsFor('buyer'));
        $this->assertCount(5, $document->fieldsFor('counterparty'));
        $this->assertSame([], $document->fieldsFor('witness'));
        $this->assertCount(3, $document->fieldsOnPage(1));
        $this->assertCount(7, $document->fieldsOnPage(2));

        $this->assertSame(1, $document->stageOf('buyer'));
        $this->assertSame(2, $document->stageOf('counterparty'));
        $this->assertNull($document->stageOf('witness'));
    }

    public function test_omitted_flags_take_their_fail_closed_defaults(): void
    {
        $raw = self::minimalDocument();
        unset($raw['fields'][0]['required'], $raw['fields'][0]['read_only']);

        $field = FieldSchemaDocument::fromArray($raw)->field('only_signature');

        $this->assertNotNull($field);
        $this->assertTrue($field->required, 'An unstated requirement must fail closed.');
        $this->assertFalse($field->readOnly);
    }

    /**
     * Spelling is canonicalised; precision is refused.
     *
     * A document may spell `650.0` for `650` or omit a defaulted property, and import tidies both
     * — those are two spellings of one value. A coordinate finer than a thousandth of a point is a
     * different matter: rounding it would be a *transformation*, and the two implementations of
     * this schema do not agree about every transformation, so the same submitted document could
     * canonicalise to two byte strings and two `field_schema_sha256`. It is refused instead, and
     * the caller rounds it themselves — a producer's rounding is its own business, and what it
     * sends is then taken literally.
     */
    public function test_a_document_with_loose_spelling_canonicalises_and_is_then_stable(): void
    {
        $raw = self::minimalDocument();
        unset($raw['fields'][0]['read_only']);
        // An integral value spelled as a float, and a canonical fractional one.
        $raw['fields'][0]['rect'] = ['x' => 60.0, 'y' => 650.0, 'width' => 170.457, 'height' => 36];

        $document = FieldSchemaDocument::fromArray($raw);
        $canonical = $document->canonicalJson();

        $this->assertStringContainsString('"x":60,"y":650,"width":170.457,"height":36', $canonical);
        $this->assertSame($canonical, FieldSchemaDocument::fromJson($canonical)->canonicalJson());
        $this->assertSame(
            json_decode($canonical, true),
            FieldSchemaDocument::fromJson($canonical)->toArray(),
        );
    }

    /**
     * From 1.1. A 1.0 document still rounds, because refusing would be a semantic tightening of a
     * published version and this schema reserves those for a major bump — see the sibling case.
     */
    public function test_a_coordinate_finer_than_the_canonical_precision_is_refused_from_1_1(): void
    {
        $raw = self::minimalDocument();
        $raw['schema_version'] = '1.1';
        $raw['fields'][0]['rect'] = ['x' => 60.00049, 'y' => 650, 'width' => 170.4567, 'height' => 36];

        try {
            FieldSchemaDocument::fromArray($raw);
            $this->fail('Expected the document to be refused rather than rounded.');
        } catch (InvalidFieldSchemaException $refused) {
            $this->assertSame(
                ['coordinate_too_precise', 'coordinate_too_precise'],
                array_map(static fn ($error) => $error->code->value, $refused->result->errors),
            );
        }
    }

    /**
     * 1.0 rounds it, exactly as it always has.
     *
     * Refusing here would take away a document that worked: an integration posting to the API and
     * never opening the editor had one canonical form for this value, deterministically. The two
     * implementations can still disagree about it — that is the cost, it is issue #105, and it is
     * a 2.0 question rather than something a minor version gets to fix by tightening underneath
     * its consumers.
     */
    public function test_a_1_0_document_still_rounds_a_finer_coordinate(): void
    {
        $raw = self::minimalDocument();
        $raw['fields'][0]['rect'] = ['x' => 60.00049, 'y' => 650, 'width' => 170.4567, 'height' => 36];

        $canonical = FieldSchemaDocument::fromArray($raw)->canonicalJson();

        $this->assertStringContainsString('"x":60,"y":650,"width":170.457,"height":36', $canonical);
    }

    public function test_a_document_built_in_code_exports_canonically(): void
    {
        $document = FieldSchemaDocument::fromArray(self::minimalDocument());

        $this->assertSame(CoordinateSpaceDeclaration::expected(), $document->coordinateSpace->toArray());
        $this->assertSame(self::minimalDocument(), $document->toArray());
    }

    public function test_from_json_rejects_malformed_json(): void
    {
        $this->expectException(InvalidFieldSchemaException::class);

        FieldSchemaDocument::fromJson('{"schema_version": "1.0",');
    }

    public function test_from_json_rejects_a_json_array(): void
    {
        try {
            FieldSchemaDocument::fromJson('[]');
            $this->fail('A JSON array is not a field document.');
        } catch (InvalidFieldSchemaException $e) {
            $this->assertSame([ValidationCode::InvalidType->value], $e->result->codes());
            $this->assertSame('', $e->errors()[0]->path);
        }
    }

    public function test_the_exception_carries_every_structured_error(): void
    {
        try {
            FieldSchemaDocument::fromArray(['schema_version' => '1.0']);
            $this->fail('A partial document must not import.');
        } catch (InvalidFieldSchemaException $e) {
            $this->assertCount(5, $e->errors());
            $this->assertSame(
                array_fill(0, 5, ValidationCode::MissingProperty->value),
                $e->result->codes(),
            );
            $this->assertStringContainsString('5 errors', $e->getMessage());

            foreach ($e->result->toArray() as $error) {
                $this->assertSame(['path', 'code', 'message'], array_keys($error));
            }
        }
    }

    /**
     * The smallest document that validates, in canonical form.
     *
     * @return array<string, mixed>
     */
    public static function minimalDocument(): array
    {
        return [
            'schema_version' => '1.0',
            'document_id' => 'doc_minimal',
            'coordinate_space' => CoordinateSpaceDeclaration::expected(),
            'recipients' => [
                ['id' => 'signer', 'name' => 'Only Signer', 'email' => 'signer@example.test'],
            ],
            'signing_order' => [['signer']],
            'fields' => [
                [
                    'id' => 'only_signature',
                    'recipient_id' => 'signer',
                    'type' => 'signature',
                    'page' => 1,
                    'rect' => ['x' => 60, 'y' => 650, 'width' => 170, 'height' => 36],
                    'required' => true,
                    'read_only' => false,
                ],
            ],
        ];
    }
}
