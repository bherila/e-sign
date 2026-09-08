<?php

declare(strict_types=1);

namespace Tests\Unit\Preparation\Schema;

use App\Domain\Preparation\Schema\CoordinateSpaceDeclaration;
use App\Domain\Preparation\Schema\FieldSchemaDocument;
use App\Domain\Preparation\Schema\FieldSchemaValidator;
use App\Domain\Preparation\Schema\PageSizes;
use App\Domain\Preparation\Schema\ValidationCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\FieldSchemaFixture;

/**
 * Every rejection rule in docs/HANDOFF.md section 7 and issue #20, one case each.
 *
 * Each case starts from the canonical NDA fixture, breaks exactly one thing, and asserts the
 * code and the JSON Pointer. The pointer matters as much as the code: the editor puts the
 * message on the offending field, so an error reported at the document root is a usability bug.
 */
class FieldSchemaValidatorTest extends TestCase
{
    private FieldSchemaValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new FieldSchemaValidator;
    }

    public function test_the_shared_fixture_is_valid(): void
    {
        $result = $this->validator->validate(FieldSchemaFixture::asArray());

        $this->assertTrue($result->isValid(), $result->describe());
    }

    public function test_the_fixture_is_valid_against_its_page_geometry_and_variable_set(): void
    {
        $result = $this->validator->validate(
            FieldSchemaFixture::asArray(),
            PageSizes::uniform(2, FieldSchemaFixture::LETTER_WIDTH, FieldSchemaFixture::LETTER_HEIGHT),
            ['recipient.name', 'recipient.company', 'recipient.title', 'envelope.agreement_date'],
        );

        $this->assertTrue($result->isValid(), $result->describe());
    }

    public function test_validate_json_reports_malformed_json_as_a_validation_error(): void
    {
        $result = $this->validator->validateJson('{"schema_version":');

        $this->assertFalse($result->isValid());
        $this->assertSame(ValidationCode::InvalidType, $result->errors[0]->code);
        $this->assertStringContainsString('not valid JSON', $result->errors[0]->message);
    }

    public function test_validate_json_accepts_the_fixture(): void
    {
        $this->assertTrue($this->validator->validateJson(FieldSchemaFixture::json())->isValid());
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $break
     */
    #[DataProvider('rejectionCases')]
    public function test_it_rejects(callable $break, ValidationCode $code, string $path): void
    {
        $result = $this->validator->validate($break(FieldSchemaFixture::asArray()));

        $this->assertFalse($result->isValid(), 'Expected '.$code->value.' at '.($path === '' ? '(document)' : $path).'.');

        $matching = array_values(array_filter(
            $result->errors,
            static fn ($error): bool => $error->code === $code && $error->path === $path,
        ));

        $this->assertCount(
            1,
            $matching,
            'Expected exactly one '.$code->value.' at "'.$path.'". Got: '.$result->describe(),
        );
    }

    /**
     * @return iterable<string, array{0: callable(array<string, mixed>): array<string, mixed>, 1: ValidationCode, 2: string}>
     */
    /**
     * The version string has to be a contract, not a label.
     *
     * A generator that stamped `1.0` and emitted a `resolved` receipt would produce a document
     * every consumer holding `field-schema-1.0.json` rejects — that file forbids undeclared
     * properties — while this service called it valid. So the members are refused in a document
     * that does not declare the version which declares them, with the code such a consumer would
     * use.
     */
    public function test_a_1_0_document_may_not_use_the_anchor_members_1_1_introduced(): void
    {
        foreach (FieldSchemaValidator::ANCHOR_MEMBERS_SINCE_1_1 as $member) {
            $document = FieldSchemaFixture::asArray();
            $document['schema_version'] = '1.0';
            unset($document['fields'][9]['anchor']['required']);
            $document['fields'][5]['anchor'][$member] = self::sampleAnchorMember($member);

            $result = (new FieldSchemaValidator)->validate($document);
            $undeclared = array_values(array_filter(
                $result->at('/fields/5/anchor/'.$member),
                static fn ($error): bool => $error->code === ValidationCode::UnknownProperty,
            ));

            // `tolerance` also draws the "only with cross_check" error here, which is a separate
            // and equally correct complaint; what matters is that the version refusal is one of
            // them.
            $this->assertCount(1, $undeclared, 'anchor.'.$member.' should be refused in a 1.0 document.');
            $this->assertStringContainsString('1.1', $undeclared[0]->message);
        }
    }

    public function test_the_same_members_are_accepted_once_the_document_declares_1_1(): void
    {
        $document = FieldSchemaFixture::asArray();
        $document['fields'][5]['anchor']['placement'] = 'replace';

        $this->assertTrue((new FieldSchemaValidator)->validate($document)->isValid());
    }

    /**
     * A cross-check receipt is the record that the check passed, so it has to survive the check.
     *
     * Without this the mode is worse than absent: a receipt naming the document's own digest
     * stops resolution running again, so a stored disagreement of any size would never be looked
     * at, and the document would carry a record saying it had been verified.
     */
    public function test_a_cross_check_receipt_that_disagrees_with_the_rect_is_refused(): void
    {
        $document = FieldSchemaFixture::asArray();
        $document['fields'][5]['anchor']['placement'] = 'cross_check';
        $document['fields'][5]['anchor']['tolerance'] = 1;
        $document['fields'][5]['anchor']['resolved'] = self::receipt([
            // The field's rect is at x 330; this says the anchor resolved 60 pt away.
            'rect' => ['x' => 390, 'y' => 650, 'width' => 170, 'height' => 36],
        ]);

        $result = (new FieldSchemaValidator)->validate($document);

        $this->assertTrue($result->hasCode(ValidationCode::AnchorCrossCheckFailed));
        $this->assertSame('/fields/5/anchor/resolved/rect/x', $result->at('/fields/5/anchor/resolved/rect/x')[0]->path);
    }

    public function test_a_cross_check_receipt_within_the_stated_tolerance_is_accepted(): void
    {
        $document = FieldSchemaFixture::asArray();
        $document['fields'][5]['anchor']['placement'] = 'cross_check';
        $document['fields'][5]['anchor']['tolerance'] = 2;
        $document['fields'][5]['anchor']['resolved'] = self::receipt([
            'rect' => ['x' => 331.5, 'y' => 650, 'width' => 170, 'height' => 36],
        ]);

        $this->assertTrue((new FieldSchemaValidator)->validate($document)->isValid());
    }

    public function test_a_cross_check_receipt_without_a_tolerance_has_nothing_to_prove(): void
    {
        $document = FieldSchemaFixture::asArray();
        $document['fields'][5]['anchor']['placement'] = 'cross_check';
        $document['fields'][5]['anchor']['resolved'] = self::receipt();

        $result = (new FieldSchemaValidator)->validate($document);

        $this->assertSame(
            [ValidationCode::MissingProperty],
            array_map(static fn ($error) => $error->code, $result->at('/fields/5/anchor/resolved')),
        );
    }

    private static function sampleAnchorMember(string $member): mixed
    {
        return match ($member) {
            'placement' => 'replace',
            'required' => true,
            'tolerance' => 2,
            default => self::receipt(),
        };
    }

    /**
     * The reason 1.1 is a new contract file rather than an edit to 1.0.
     *
     * A document written before the anchor members existed says `"1.0"`, and this build still
     * reads it — and canonicalises it to exactly the bytes it arrived as, version included. That
     * is not a nicety: an envelope's `field_schema_sha256` is what every attestation on it binds,
     * so a canonical form that grew a property, or a version string that moved on its own, would
     * invalidate the evidence for every anchored agreement already signed.
     */
    public function test_a_1_0_document_still_imports_and_keeps_its_exact_bytes(): void
    {
        $legacy = FieldSchemaFixture::asArray();
        $legacy['schema_version'] = '1.0';
        unset($legacy['fields'][9]['anchor']['required']);

        $this->assertTrue((new FieldSchemaValidator)->validate($legacy)->isValid());

        $document = FieldSchemaDocument::fromArray($legacy);

        $this->assertSame('1.0', $document->schemaVersion->toString());
        $this->assertSame($legacy, $document->toArray());
        $this->assertSame(
            hash('sha256', $document->canonicalJson()),
            hash('sha256', FieldSchemaDocument::fromArray($document->toArray())->canonicalJson()),
        );
    }

    /**
     * A run's box is its advance by the font's ascent plus descent, so a heading near the top of
     * the page starts above the CropBox edge and a run at the margin ends on it. Both are
     * ordinary documents, and a receipt measuring one has to import — otherwise the service
     * writes receipts it cannot read back, and an anchored request that worked yesterday fails.
     */
    public function test_a_receipt_may_measure_text_that_overhangs_the_page(): void
    {
        $document = FieldSchemaFixture::asArray();
        $document['fields'][5]['anchor']['resolved'] = self::receipt([
            'anchor_rect' => ['x' => -4, 'y' => -2.5, 'width' => 620, 'height' => 12],
        ]);

        $result = (new FieldSchemaValidator)->validate(
            $document,
            PageSizes::uniform(2, FieldSchemaFixture::LETTER_WIDTH, FieldSchemaFixture::LETTER_HEIGHT),
        );

        $this->assertTrue($result->isValid(), $result->describe());

        // The same numbers in a *placement* are still refused: the distinction is the point.
        $placed = FieldSchemaFixture::asArray();
        $placed['fields'][5]['rect'] = ['x' => -4, 'y' => -2.5, 'width' => 620, 'height' => 12];

        $this->assertTrue((new FieldSchemaValidator)->validate($placed)->hasCode(ValidationCode::CoordinateNegative));
    }

    /**
     * A well-formed resolution receipt for the fixture's anchored counterparty signature, with
     * one part swapped out. Its `rect` is the field's own, which `replace` mode requires.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function receipt(array $overrides = []): array
    {
        return array_replace([
            'document_sha256' => str_repeat('a', 64),
            'page' => 2,
            'occurrence_index' => 1,
            'anchor_rect' => ['x' => 330, 'y' => 622.4, 'width' => 165.6, 'height' => 12],
            'rect' => ['x' => 330, 'y' => 650, 'width' => 170, 'height' => 36],
        ], $overrides);
    }

    public static function rejectionCases(): iterable
    {
        yield 'a partial document missing whole sections' => [
            static function (array $document): array {
                unset($document['fields']);

                return $document;
            },
            ValidationCode::MissingProperty,
            '',
        ];

        yield 'a field missing its rect' => [
            static function (array $document): array {
                unset($document['fields'][0]['rect']);

                return $document;
            },
            ValidationCode::MissingProperty,
            '/fields/0',
        ];

        yield 'an undeclared document property' => [
            static function (array $document): array {
                $document['fields_v2'] = [];

                return $document;
            },
            ValidationCode::UnknownProperty,
            '/fields_v2',
        ];

        yield 'an undeclared field property' => [
            static function (array $document): array {
                $document['fields'][1]['font_size'] = 12;

                return $document;
            },
            ValidationCode::UnknownProperty,
            '/fields/1/font_size',
        ];

        yield 'an undeclared rect property' => [
            static function (array $document): array {
                $document['fields'][0]['rect']['rotation'] = 90;

                return $document;
            },
            ValidationCode::UnknownProperty,
            '/fields/0/rect/rotation',
        ];

        yield 'a missing schema_version' => [
            static function (array $document): array {
                unset($document['schema_version']);

                return $document;
            },
            ValidationCode::MissingProperty,
            '',
        ];

        yield 'an unknown major schema_version' => [
            static function (array $document): array {
                $document['schema_version'] = '2.0';

                return $document;
            },
            ValidationCode::SchemaVersionUnsupported,
            '/schema_version',
        ];

        yield 'a newer minor schema_version' => [
            static function (array $document): array {
                $document['schema_version'] = '1.7';

                return $document;
            },
            ValidationCode::SchemaVersionUnsupported,
            '/schema_version',
        ];

        yield 'a malformed schema_version' => [
            static function (array $document): array {
                $document['schema_version'] = 'v1';

                return $document;
            },
            ValidationCode::SchemaVersionUnsupported,
            '/schema_version',
        ];

        yield 'a non-string schema_version' => [
            static function (array $document): array {
                $document['schema_version'] = 1.0;

                return $document;
            },
            ValidationCode::InvalidType,
            '/schema_version',
        ];

        yield 'a coordinate space in the wrong unit' => [
            static function (array $document): array {
                $document['coordinate_space']['unit'] = 'percent';

                return $document;
            },
            ValidationCode::UnsupportedCoordinateSpace,
            '/coordinate_space/unit',
        ];

        yield 'a bottom-left origin' => [
            static function (array $document): array {
                $document['coordinate_space']['origin'] = 'bottom-left';

                return $document;
            },
            ValidationCode::UnsupportedCoordinateSpace,
            '/coordinate_space/origin',
        ];

        yield 'the media box instead of the crop box' => [
            static function (array $document): array {
                $document['coordinate_space']['page_box'] = 'media';

                return $document;
            },
            ValidationCode::UnsupportedCoordinateSpace,
            '/coordinate_space/page_box',
        ];

        yield 'unrotated coordinates' => [
            static function (array $document): array {
                $document['coordinate_space']['rotation'] = 'raw';

                return $document;
            },
            ValidationCode::UnsupportedCoordinateSpace,
            '/coordinate_space/rotation',
        ];

        yield 'zero-based page numbering' => [
            static function (array $document): array {
                $document['coordinate_space']['page_index_base'] = 0;

                return $document;
            },
            ValidationCode::UnsupportedCoordinateSpace,
            '/coordinate_space/page_index_base',
        ];

        yield 'an incomplete coordinate space' => [
            static function (array $document): array {
                unset($document['coordinate_space']['rotation']);

                return $document;
            },
            ValidationCode::MissingProperty,
            '/coordinate_space',
        ];

        yield 'a coordinate space that is not an object' => [
            static function (array $document): array {
                $document['coordinate_space'] = 'native';

                return $document;
            },
            ValidationCode::InvalidType,
            '/coordinate_space',
        ];

        yield 'a duplicate recipient id' => [
            static function (array $document): array {
                $document['recipients'][1]['id'] = 'buyer';
                $document['signing_order'] = [['buyer']];

                return $document;
            },
            ValidationCode::DuplicateId,
            '/recipients/1/id',
        ];

        yield 'a duplicate field id' => [
            static function (array $document): array {
                $document['fields'][1]['id'] = 'buyer_signature';

                return $document;
            },
            ValidationCode::DuplicateId,
            '/fields/1/id',
        ];

        yield 'a duplicate template alias' => [
            static function (array $document): array {
                $document['fields'][5]['alias'] = 'buyer_signature_block';

                return $document;
            },
            ValidationCode::DuplicateAlias,
            '/fields/5/alias',
        ];

        yield 'an id with illegal characters' => [
            static function (array $document): array {
                $document['fields'][0]['id'] = 'buyer signature!';

                return $document;
            },
            ValidationCode::InvalidFormat,
            '/fields/0/id',
        ];

        yield 'an empty document_id' => [
            static function (array $document): array {
                $document['document_id'] = '';

                return $document;
            },
            ValidationCode::InvalidFormat,
            '/document_id',
        ];

        yield 'a field bound to a nonexistent recipient' => [
            static function (array $document): array {
                $document['fields'][0]['recipient_id'] = 'witness';

                return $document;
            },
            ValidationCode::UnknownRecipient,
            '/fields/0/recipient_id',
        ];

        yield 'a signing order naming a nonexistent recipient' => [
            static function (array $document): array {
                $document['signing_order'][1][] = 'witness';

                return $document;
            },
            ValidationCode::UnknownRecipient,
            '/signing_order/1/1',
        ];

        yield 'a recipient in no signing stage' => [
            static function (array $document): array {
                $document['signing_order'] = [['buyer']];

                return $document;
            },
            ValidationCode::RecipientNotInSigningOrder,
            '/recipients/1/id',
        ];

        yield 'a recipient in two signing stages' => [
            static function (array $document): array {
                $document['signing_order'] = [['buyer'], ['counterparty'], ['buyer']];

                return $document;
            },
            ValidationCode::RecipientDuplicatedInSigningOrder,
            '/signing_order/2/0',
        ];

        yield 'a recipient twice in one signing stage' => [
            static function (array $document): array {
                $document['signing_order'] = [['buyer', 'buyer'], ['counterparty']];

                return $document;
            },
            ValidationCode::RecipientDuplicatedInSigningOrder,
            '/signing_order/0/1',
        ];

        yield 'no recipients at all' => [
            static function (array $document): array {
                $document['recipients'] = [];

                return $document;
            },
            ValidationCode::EmptyCollection,
            '/recipients',
        ];

        yield 'an empty signing order' => [
            static function (array $document): array {
                $document['signing_order'] = [];

                return $document;
            },
            ValidationCode::EmptyCollection,
            '/signing_order',
        ];

        yield 'an empty signing stage' => [
            static function (array $document): array {
                $document['signing_order'] = [['buyer'], [], ['counterparty']];

                return $document;
            },
            ValidationCode::EmptyCollection,
            '/signing_order/1',
        ];

        yield 'a signing stage that is not an array' => [
            static function (array $document): array {
                $document['signing_order'] = ['buyer', ['counterparty']];

                return $document;
            },
            ValidationCode::InvalidType,
            '/signing_order/0',
        ];

        yield 'an unsupported field type' => [
            static function (array $document): array {
                $document['fields'][2]['type'] = 'radio_group';

                return $document;
            },
            ValidationCode::UnsupportedFieldType,
            '/fields/2/type',
        ];

        yield 'a page below one' => [
            static function (array $document): array {
                $document['fields'][0]['page'] = 0;

                return $document;
            },
            ValidationCode::PageOutOfRange,
            '/fields/0/page',
        ];

        yield 'a negative page' => [
            static function (array $document): array {
                $document['fields'][0]['page'] = -3;

                return $document;
            },
            ValidationCode::PageOutOfRange,
            '/fields/0/page',
        ];

        yield 'a non-integer page' => [
            static function (array $document): array {
                $document['fields'][0]['page'] = '1';

                return $document;
            },
            ValidationCode::InvalidType,
            '/fields/0/page',
        ];

        yield 'a not-a-number coordinate' => [
            static function (array $document): array {
                $document['fields'][0]['rect']['x'] = NAN;

                return $document;
            },
            ValidationCode::CoordinateNotFinite,
            '/fields/0/rect/x',
        ];

        yield 'an infinite coordinate' => [
            static function (array $document): array {
                $document['fields'][0]['rect']['y'] = INF;

                return $document;
            },
            ValidationCode::CoordinateNotFinite,
            '/fields/0/rect/y',
        ];

        yield 'a coordinate that is a numeric string' => [
            static function (array $document): array {
                $document['fields'][0]['rect']['x'] = '60';

                return $document;
            },
            ValidationCode::InvalidType,
            '/fields/0/rect/x',
        ];

        yield 'a negative x' => [
            static function (array $document): array {
                $document['fields'][0]['rect']['x'] = -0.5;

                return $document;
            },
            ValidationCode::CoordinateNegative,
            '/fields/0/rect/x',
        ];

        yield 'a negative y' => [
            static function (array $document): array {
                $document['fields'][0]['rect']['y'] = -1;

                return $document;
            },
            ValidationCode::CoordinateNegative,
            '/fields/0/rect/y',
        ];

        yield 'a zero width' => [
            static function (array $document): array {
                $document['fields'][0]['rect']['width'] = 0;

                return $document;
            },
            ValidationCode::DimensionNotPositive,
            '/fields/0/rect/width',
        ];

        yield 'a negative height' => [
            static function (array $document): array {
                $document['fields'][0]['rect']['height'] = -36;

                return $document;
            },
            ValidationCode::DimensionNotPositive,
            '/fields/0/rect/height',
        ];

        yield 'a rect that is not an object' => [
            static function (array $document): array {
                $document['fields'][0]['rect'] = [60, 650, 170, 36];

                return $document;
            },
            ValidationCode::InvalidType,
            '/fields/0/rect',
        ];

        yield 'a non-boolean required flag' => [
            static function (array $document): array {
                $document['fields'][0]['required'] = 'yes';

                return $document;
            },
            ValidationCode::InvalidType,
            '/fields/0/required',
        ];

        yield 'a non-boolean read_only flag' => [
            static function (array $document): array {
                $document['fields'][0]['read_only'] = 0;

                return $document;
            },
            ValidationCode::InvalidType,
            '/fields/0/read_only',
        ];

        yield 'an empty label' => [
            static function (array $document): array {
                $document['fields'][0]['label'] = '';

                return $document;
            },
            ValidationCode::InvalidFormat,
            '/fields/0/label',
        ];

        yield 'an overlong label' => [
            static function (array $document): array {
                $document['fields'][0]['label'] = str_repeat('x', 201);

                return $document;
            },
            ValidationCode::InvalidFormat,
            '/fields/0/label',
        ];

        yield 'a malformed prefill variable' => [
            static function (array $document): array {
                $document['fields'][2]['prefill']['variable'] = 'Recipient Name';

                return $document;
            },
            ValidationCode::InvalidFormat,
            '/fields/2/prefill/variable',
        ];

        yield 'a prefill without a variable' => [
            static function (array $document): array {
                $document['fields'][2]['prefill'] = [];

                return $document;
            },
            ValidationCode::MissingProperty,
            '/fields/2/prefill',
        ];

        yield 'an undeclared prefill property' => [
            static function (array $document): array {
                $document['fields'][2]['prefill']['fallback'] = 'Anonymous';

                return $document;
            },
            ValidationCode::UnknownProperty,
            '/fields/2/prefill/fallback',
        ];

        yield 'an empty anchor text' => [
            static function (array $document): array {
                $document['fields'][5]['anchor']['text'] = '';

                return $document;
            },
            ValidationCode::InvalidFormat,
            '/fields/5/anchor/text',
        ];

        yield 'an anchor without text' => [
            static function (array $document): array {
                unset($document['fields'][5]['anchor']['text']);

                return $document;
            },
            ValidationCode::MissingProperty,
            '/fields/5/anchor',
        ];

        yield 'an anchor that does not say which match it means' => [
            static function (array $document): array {
                unset($document['fields'][5]['anchor']['occurrence']);

                return $document;
            },
            ValidationCode::MissingProperty,
            '/fields/5/anchor',
        ];

        yield 'an anchor occurrence of all, which one field cannot represent' => [
            static function (array $document): array {
                $document['fields'][5]['anchor']['occurrence'] = 'all';

                return $document;
            },
            ValidationCode::InvalidFormat,
            '/fields/5/anchor/occurrence',
        ];

        yield 'an anchor occurrence that is neither sole nor an index' => [
            static function (array $document): array {
                $document['fields'][5]['anchor']['occurrence'] = 1.5;

                return $document;
            },
            ValidationCode::InvalidType,
            '/fields/5/anchor/occurrence',
        ];

        yield 'an optional anchor on a required field' => [
            static function (array $document): array {
                $document['fields'][5]['anchor']['required'] = false;

                return $document;
            },
            ValidationCode::AnchorOptionalOnRequiredField,
            '/fields/5/anchor/required',
        ];

        // In cross-check mode the rectangle is authoritative and the anchor only confirms it, so
        // "the text may be absent" has nothing to omit — honouring it would delete a field the
        // document positioned itself.
        yield 'an optional anchor that only cross-checks a rectangle it cannot omit' => [
            static function (array $document): array {
                $document['fields'][9]['anchor']['placement'] = 'cross_check';
                $document['fields'][9]['anchor']['required'] = false;

                return $document;
            },
            ValidationCode::InvalidFormat,
            '/fields/9/anchor/required',
        ];

        yield 'a tolerance on an anchor that decides the position outright' => [
            static function (array $document): array {
                $document['fields'][5]['anchor']['tolerance'] = 2;

                return $document;
            },
            ValidationCode::InvalidFormat,
            '/fields/5/anchor/tolerance',
        ];

        yield 'an undeclared anchor placement' => [
            static function (array $document): array {
                $document['fields'][5]['anchor']['placement'] = 'nudge';

                return $document;
            },
            ValidationCode::InvalidFormat,
            '/fields/5/anchor/placement',
        ];

        yield 'a resolution receipt whose digest is not one' => [
            static function (array $document): array {
                $document['fields'][5]['anchor']['resolved'] = self::receipt(['document_sha256' => 'not-a-digest']);

                return $document;
            },
            ValidationCode::InvalidFormat,
            '/fields/5/anchor/resolved/document_sha256',
        ];

        // The receipt records where the field went, so in `replace` mode it has to be the
        // field's own rectangle. A document whose field sits somewhere its own receipt does not
        // describe is a document nobody can check.
        yield 'a receipt describing a rectangle the field is not at' => [
            static function (array $document): array {
                $document['fields'][5]['anchor']['resolved'] = self::receipt([
                    'rect' => ['x' => 999, 'y' => 650, 'width' => 170, 'height' => 36],
                ]);

                return $document;
            },
            ValidationCode::InvalidFormat,
            '/fields/5/anchor/resolved/rect/x',
        ];

        yield 'a measured anchor rect with a negative extent' => [
            static function (array $document): array {
                $document['fields'][5]['anchor']['resolved'] = self::receipt([
                    'anchor_rect' => ['x' => 330, 'y' => 622.4, 'width' => -1, 'height' => 12],
                ]);

                return $document;
            },
            ValidationCode::DimensionNotPositive,
            '/fields/5/anchor/resolved/anchor_rect/width',
        ];

        yield 'an anchor origin that is not a declared corner' => [
            static function (array $document): array {
                $document['fields'][5]['anchor']['origin'] = 'centre';

                return $document;
            },
            ValidationCode::InvalidFormat,
            '/fields/5/anchor/origin',
        ];

        yield 'a zero anchor occurrence' => [
            static function (array $document): array {
                $document['fields'][5]['anchor']['occurrence'] = 0;

                return $document;
            },
            ValidationCode::InvalidFormat,
            '/fields/5/anchor/occurrence',
        ];

        yield 'a non-finite anchor offset' => [
            static function (array $document): array {
                $document['fields'][5]['anchor']['offset']['dy'] = -INF;

                return $document;
            },
            ValidationCode::CoordinateNotFinite,
            '/fields/5/anchor/offset/dy',
        ];

        yield 'an anchor offset missing an axis' => [
            static function (array $document): array {
                unset($document['fields'][5]['anchor']['offset']['dx']);

                return $document;
            },
            ValidationCode::MissingProperty,
            '/fields/5/anchor/offset',
        ];

        yield 'a recipient email that is not an address' => [
            static function (array $document): array {
                $document['recipients'][0]['email'] = 'buyer at example.test';

                return $document;
            },
            ValidationCode::InvalidEmail,
            '/recipients/0/email',
        ];

        yield 'an empty recipient name' => [
            static function (array $document): array {
                $document['recipients'][0]['name'] = '';

                return $document;
            },
            ValidationCode::InvalidFormat,
            '/recipients/0/name',
        ];

        yield 'an undeclared recipient property' => [
            static function (array $document): array {
                $document['recipients'][0]['phone'] = '+1-555-0100';

                return $document;
            },
            ValidationCode::UnknownProperty,
            '/recipients/0/phone',
        ];

        yield 'recipients that are not an array' => [
            static function (array $document): array {
                $document['recipients'] = ['buyer' => ['id' => 'buyer', 'name' => 'B', 'email' => 'b@example.test']];

                return $document;
            },
            ValidationCode::InvalidType,
            '/recipients',
        ];

        yield 'fields that are not an array' => [
            static function (array $document): array {
                $document['fields'] = ['buyer_signature' => []];

                return $document;
            },
            ValidationCode::InvalidType,
            '/fields',
        ];
    }

    public function test_an_empty_field_list_is_a_valid_draft(): void
    {
        $document = FieldSchemaFixture::asArray();
        $document['fields'] = [];

        $this->assertTrue($this->validator->validate($document)->isValid());
    }

    public function test_a_page_beyond_the_document_is_rejected_only_when_the_page_count_is_known(): void
    {
        $document = FieldSchemaFixture::asArray();
        $document['fields'][0]['page'] = 9;

        $this->assertTrue(
            $this->validator->validate($document)->isValid(),
            'Without page sizes the page count is unknown, and an unknown check is not reported as a pass or a failure.',
        );

        $result = $this->validator->validate($document, PageSizes::uniform(2, 612, 792));

        $this->assertSame([ValidationCode::PageOutOfRange->value], $result->codes());
        $this->assertSame('/fields/0/page', $result->errors[0]->path);
        $this->assertStringContainsString('2-page document', $result->errors[0]->message);
    }

    public function test_a_rect_past_the_right_edge_is_rejected(): void
    {
        $document = FieldSchemaFixture::asArray();
        $document['fields'][0]['rect'] = ['x' => 500, 'y' => 650, 'width' => 170, 'height' => 36];

        $this->assertTrue($this->validator->validate($document)->isValid());

        $result = $this->validator->validate($document, PageSizes::uniform(2, 612, 792));

        $this->assertSame([ValidationCode::RectOutOfPage->value], $result->codes());
        $this->assertSame('/fields/0/rect', $result->errors[0]->path);
    }

    public function test_a_rect_past_the_bottom_edge_is_rejected(): void
    {
        $document = FieldSchemaFixture::asArray();
        $document['fields'][0]['rect'] = ['x' => 60, 'y' => 780, 'width' => 170, 'height' => 36];

        $result = $this->validator->validate($document, PageSizes::uniform(2, 612, 792));

        $this->assertTrue($result->hasCode(ValidationCode::RectOutOfPage));
    }

    public function test_a_rect_flush_against_the_page_edge_is_accepted(): void
    {
        $document = FieldSchemaFixture::asArray();
        $document['fields'][0]['rect'] = ['x' => 442, 'y' => 756, 'width' => 170, 'height' => 36];

        $result = $this->validator->validate($document, PageSizes::uniform(2, 612, 792));

        $this->assertTrue($result->isValid(), $result->describe());
    }

    public function test_rects_are_checked_per_page_against_mixed_page_sizes(): void
    {
        $document = FieldSchemaFixture::asArray();
        $document['fields'][1]['page'] = 1;
        $document['fields'][1]['rect'] = ['x' => 500, 'y' => 100, 'width' => 100, 'height' => 24];

        // Page 1 is A4 portrait (595.276 pt wide), page 2 is US Legal.
        $sizes = PageSizes::fromList([
            ['width' => 595.276, 'height' => 841.89],
            ['width' => 612, 'height' => 1008],
        ]);

        $result = $this->validator->validate($document, $sizes);

        $this->assertTrue($result->hasCode(ValidationCode::RectOutOfPage), $result->describe());
        $this->assertSame('/fields/1/rect', $result->errors[0]->path);

        $document['fields'][1]['rect']['width'] = 95;
        $this->assertTrue($this->validator->validate($document, $sizes)->isValid());
    }

    public function test_an_unresolved_prefill_variable_is_rejected(): void
    {
        $result = $this->validator->validate(
            FieldSchemaFixture::asArray(),
            null,
            ['recipient.name', 'recipient.company', 'recipient.title'],
        );

        $this->assertSame([ValidationCode::UnresolvedPrefillVariable->value], $result->codes());
        $this->assertSame('/fields/7/prefill/variable', $result->errors[0]->path);
        $this->assertStringContainsString('envelope.agreement_date', $result->errors[0]->message);
    }

    public function test_prefill_variables_are_not_checked_without_a_variable_set(): void
    {
        $this->assertTrue($this->validator->validate(FieldSchemaFixture::asArray(), null, null)->isValid());
    }

    public function test_an_empty_variable_set_resolves_nothing(): void
    {
        $result = $this->validator->validate(FieldSchemaFixture::asArray(), null, []);

        $this->assertCount(4, $result->errors);
        $this->assertSame(
            array_fill(0, 4, ValidationCode::UnresolvedPrefillVariable->value),
            $result->codes(),
        );
    }

    public function test_it_reports_every_problem_in_one_pass(): void
    {
        $document = FieldSchemaFixture::asArray();
        $document['fields'][0]['recipient_id'] = 'witness';
        $document['fields'][1]['type'] = 'radio_group';
        $document['fields'][2]['rect']['width'] = 0;
        $document['fields'][3]['page'] = 0;

        $result = $this->validator->validate($document);

        $this->assertSame([
            ValidationCode::UnknownRecipient->value,
            ValidationCode::UnsupportedFieldType->value,
            ValidationCode::DimensionNotPositive->value,
            ValidationCode::PageOutOfRange->value,
        ], $result->codes());
    }

    public function test_reference_checks_are_skipped_when_the_recipients_section_is_unusable(): void
    {
        $document = FieldSchemaFixture::asArray();
        $document['recipients'] = 'buyer, counterparty';

        $result = $this->validator->validate($document);

        // One error about recipients, not ten cascading "unknown recipient" errors on the fields.
        $this->assertSame([ValidationCode::InvalidType->value], $result->codes());
    }

    public function test_a_completely_empty_document_reports_every_missing_section(): void
    {
        $result = $this->validator->validate([]);

        $this->assertCount(count(FieldSchemaValidator::DOCUMENT_REQUIRED), $result->errors);
        $this->assertSame([''], array_values(array_unique(array_map(
            static fn ($error): string => $error->path,
            $result->errors,
        ))));
    }

    public function test_the_expected_coordinate_space_is_the_one_the_fixture_declares(): void
    {
        $this->assertSame(
            CoordinateSpaceDeclaration::expected(),
            FieldSchemaFixture::asArray()['coordinate_space'],
        );
    }
}
