<?php

declare(strict_types=1);

namespace Tests\Unit\Preparation\Schema;

use App\Domain\Preparation\Schema\CoordinateSpaceDeclaration;
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
