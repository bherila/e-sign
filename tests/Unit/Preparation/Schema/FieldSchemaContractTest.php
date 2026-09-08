<?php

declare(strict_types=1);

namespace Tests\Unit\Preparation\Schema;

use App\Domain\Preparation\Schema\AnchorPlacement;
use App\Domain\Preparation\Schema\CoordinateSpaceDeclaration;
use App\Domain\Preparation\Schema\FieldDefinition;
use App\Domain\Preparation\Schema\FieldSchemaValidator;
use App\Domain\Preparation\Schema\FieldType;
use App\Domain\Preparation\Schema\SchemaVersion;
use App\Domain\Preparation\Schema\ValidationCode;
use App\Domain\Preparation\Text\AnchorOrigin;
use PHPUnit\Framework\TestCase;
use Tests\Support\FieldSchemaFixture;

/**
 * `resources/schema/field-schema-1.0.json` is the published contract the editor and the native
 * API share, and this module is its PHP implementation. Nothing stops the two drifting except
 * this test: it pins the schema file's property lists, enums, constants, patterns, limits, and
 * defaults against the PHP classes, in both directions.
 *
 * The JSON Schema is also enforced directly, with `ajv`, in `resources/js/schema/*.test.ts`.
 * Between the two, a change to one side that is not made on the other fails a suite.
 */
class FieldSchemaContractTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function definition(string $name): array
    {
        /** @var array<string, array<string, mixed>> $defs */
        $defs = FieldSchemaFixture::schema()['$defs'];

        return $defs[$name];
    }

    public function test_it_is_a_draft_2020_12_schema(): void
    {
        $schema = FieldSchemaFixture::schema();

        $this->assertSame('https://json-schema.org/draft/2020-12/schema', $schema['$schema']);
        $this->assertSame('object', $schema['type']);
        $this->assertFalse($schema['additionalProperties'], 'The document must reject undeclared properties.');
    }

    public function test_the_document_properties_match_the_php_importer(): void
    {
        $schema = FieldSchemaFixture::schema();

        $this->assertSame(FieldSchemaValidator::DOCUMENT_REQUIRED, $schema['required']);
        $this->assertSame(FieldSchemaValidator::DOCUMENT_REQUIRED, array_keys($schema['properties']));
    }

    public function test_the_schema_version_constant_matches(): void
    {
        $schema = FieldSchemaFixture::schema();

        $this->assertSame(SchemaVersion::CURRENT, $schema['properties']['schema_version']['const']);
        $this->assertStringEndsWith('field-schema-'.SchemaVersion::CURRENT.'.json', $schema['$id']);
        $this->assertStringEndsWith('field-schema-'.SchemaVersion::CURRENT.'.json', FieldSchemaFixture::schemaPath());
    }

    public function test_the_coordinate_space_is_pinned_value_by_value(): void
    {
        $space = self::definition('coordinate_space');
        $expected = CoordinateSpaceDeclaration::expected();

        $this->assertFalse($space['additionalProperties']);
        $this->assertSame(array_keys($expected), $space['required']);
        $this->assertSame(array_keys($expected), array_keys($space['properties']));

        foreach ($expected as $key => $value) {
            $this->assertSame(
                $value,
                $space['properties'][$key]['const'],
                'coordinate_space.'.$key.' must be a const in the schema, not an open type.',
            );
        }
    }

    public function test_the_field_types_match_the_php_enum(): void
    {
        $this->assertSame(FieldType::values(), self::definition('field_type')['enum']);
        $this->assertCount(9, FieldType::values());
    }

    public function test_the_field_properties_match_the_php_importer(): void
    {
        $field = self::definition('field');

        $this->assertFalse($field['additionalProperties']);
        $this->assertSame(FieldSchemaValidator::FIELD_REQUIRED, $field['required']);
        $this->assertSame(
            array_merge(FieldSchemaValidator::FIELD_REQUIRED, FieldSchemaValidator::FIELD_OPTIONAL),
            array_keys($field['properties']),
        );
    }

    public function test_the_recipient_properties_match_the_php_importer(): void
    {
        $recipient = self::definition('recipient');

        $this->assertFalse($recipient['additionalProperties']);
        $this->assertSame(FieldSchemaValidator::RECIPIENT_REQUIRED, $recipient['required']);
        $this->assertSame(
            array_merge(FieldSchemaValidator::RECIPIENT_REQUIRED, FieldSchemaValidator::RECIPIENT_OPTIONAL),
            array_keys($recipient['properties']),
        );
    }

    public function test_the_rect_constraints_match_the_php_importer(): void
    {
        $rect = self::definition('rect');

        $this->assertFalse($rect['additionalProperties']);
        $this->assertSame(FieldSchemaValidator::RECT_REQUIRED, $rect['required']);
        $this->assertSame(FieldSchemaValidator::RECT_REQUIRED, array_keys($rect['properties']));
        $this->assertSame(0, $rect['properties']['x']['minimum']);
        $this->assertSame(0, $rect['properties']['y']['minimum']);
        $this->assertSame(0, $rect['properties']['width']['exclusiveMinimum']);
        $this->assertSame(0, $rect['properties']['height']['exclusiveMinimum']);
    }

    public function test_the_defaults_match_the_php_value_objects(): void
    {
        $field = self::definition('field');

        $this->assertSame(FieldDefinition::DEFAULT_REQUIRED, $field['properties']['required']['default']);
        $this->assertSame(FieldDefinition::DEFAULT_READ_ONLY, $field['properties']['read_only']['default']);
        $this->assertSame(
            AnchorPlacement::DEFAULT_ORIGIN->value,
            self::definition('anchor')['properties']['origin']['default'],
        );
    }

    public function test_the_patterns_and_limits_match_the_php_importer(): void
    {
        $identifier = self::definition('identifier');

        $this->assertSame(trim(FieldSchemaValidator::IDENTIFIER_PATTERN, '/'), $identifier['pattern']);
        $this->assertSame(FieldSchemaValidator::IDENTIFIER_MAX_LENGTH, $identifier['maxLength']);
        $this->assertSame(1, $identifier['minLength']);

        $prefill = self::definition('prefill');
        $this->assertFalse($prefill['additionalProperties']);
        $this->assertSame(['variable'], $prefill['required']);
        $this->assertSame(trim(FieldSchemaValidator::VARIABLE_PATTERN, '/'), $prefill['properties']['variable']['pattern']);
        $this->assertSame(FieldSchemaValidator::VARIABLE_MAX_LENGTH, $prefill['properties']['variable']['maxLength']);

        $recipient = self::definition('recipient');
        $this->assertSame(trim(FieldSchemaValidator::EMAIL_PATTERN, '/'), $recipient['properties']['email']['pattern']);
        $this->assertSame(FieldSchemaValidator::EMAIL_MAX_LENGTH, $recipient['properties']['email']['maxLength']);
        $this->assertSame(FieldSchemaValidator::NAME_MAX_LENGTH, $recipient['properties']['name']['maxLength']);
        $this->assertSame(FieldSchemaValidator::ROLE_MAX_LENGTH, $recipient['properties']['role']['maxLength']);

        $field = self::definition('field');
        $this->assertSame(FieldSchemaValidator::LABEL_MAX_LENGTH, $field['properties']['label']['maxLength']);
        $this->assertSame(1, $field['properties']['page']['minimum']);
        $this->assertSame('integer', $field['properties']['page']['type']);

        $anchor = self::definition('anchor');
        $this->assertFalse($anchor['additionalProperties']);
        $this->assertSame(FieldSchemaValidator::ANCHOR_REQUIRED, $anchor['required']);
        $this->assertSame(
            array_merge(FieldSchemaValidator::ANCHOR_REQUIRED, FieldSchemaValidator::ANCHOR_OPTIONAL),
            array_keys($anchor['properties']),
        );
        $this->assertSame(FieldSchemaValidator::ANCHOR_TEXT_MAX_LENGTH, $anchor['properties']['text']['maxLength']);
        $this->assertFalse($anchor['properties']['offset']['additionalProperties']);
        $this->assertSame(['dx', 'dy'], $anchor['properties']['offset']['required']);
    }

    /**
     * The anchor placement request is the serialised form of the Text module's own semantics.
     *
     * `Text\AnchorOccurrence` refuses a "first match wins" default, so `occurrence` is required
     * here and offers exactly the two modes a single field can represent: "sole" or an index.
     * `all` places one box per match and is deliberately absent from the schema.
     */
    public function test_the_anchor_shape_matches_the_text_modules_semantics(): void
    {
        $anchor = self::definition('anchor');

        $this->assertContains('occurrence', $anchor['required'], 'An anchor must say which match it means.');
        $this->assertArrayNotHasKey(
            'default',
            $anchor['properties']['occurrence'],
            'A default occurrence would be the "first match wins" fallback the Text module refuses.',
        );
        $this->assertSame(
            [['const' => AnchorPlacement::OCCURRENCE_SOLE], ['type' => 'integer', 'minimum' => 1]],
            $anchor['properties']['occurrence']['oneOf'],
        );
        $this->assertStringNotContainsString('"all"', json_encode($anchor['properties']['occurrence']['oneOf']) ?: '');

        $this->assertSame(
            array_map(static fn (AnchorOrigin $corner): string => $corner->value, AnchorOrigin::cases()),
            $anchor['properties']['origin']['enum'],
        );
    }

    public function test_the_signing_order_shape_is_stages_of_recipient_ids(): void
    {
        $schema = FieldSchemaFixture::schema();
        $order = $schema['properties']['signing_order'];

        $this->assertSame('array', $order['type']);
        $this->assertSame(1, $order['minItems']);
        $this->assertSame('array', $order['items']['type']);
        $this->assertSame(1, $order['items']['minItems']);
        $this->assertSame('#/$defs/identifier', $order['items']['items']['$ref']);
    }

    /**
     * The TypeScript mirror carries the same vocabulary.
     *
     * The editor maps these codes to messages and the native API returns them, so a code added
     * on one side only is a code the other side cannot handle. Reading the source is crude, but
     * the alternative — generating one from the other — would need a build step in a repository
     * that deliberately keeps Node out of the production path.
     */
    public function test_the_typescript_mirror_declares_the_same_codes_and_field_types(): void
    {
        $source = FieldSchemaFixture::typescriptMirrorSource();

        $this->assertSame(
            array_map(static fn (ValidationCode $code): string => $code->value, ValidationCode::cases()),
            self::declaredStrings($source, 'VALIDATION_CODES'),
            'resources/js/schema/fieldSchema.ts VALIDATION_CODES must match ValidationCode, in order.',
        );

        $this->assertSame(
            FieldType::values(),
            self::declaredStrings($source, 'FIELD_TYPES'),
            'resources/js/schema/fieldSchema.ts FIELD_TYPES must match FieldType, in order.',
        );

        foreach ([
            'DOCUMENT_REQUIRED' => FieldSchemaValidator::DOCUMENT_REQUIRED,
            'RECIPIENT_REQUIRED' => FieldSchemaValidator::RECIPIENT_REQUIRED,
            'RECIPIENT_OPTIONAL' => FieldSchemaValidator::RECIPIENT_OPTIONAL,
            'FIELD_REQUIRED' => FieldSchemaValidator::FIELD_REQUIRED,
            'FIELD_OPTIONAL' => FieldSchemaValidator::FIELD_OPTIONAL,
            'RECT_REQUIRED' => FieldSchemaValidator::RECT_REQUIRED,
        ] as $constant => $expected) {
            $this->assertSame($expected, self::declaredStrings($source, $constant), $constant.' must match.');
        }
    }

    /**
     * The string literals of an `export const NAME = [...] as const;` declaration.
     *
     * @return list<string>
     */
    private static function declaredStrings(string $source, string $constant): array
    {
        $pattern = '/export const '.preg_quote($constant, '/').'\s*=\s*\[(.*?)\]\s*as const;/s';

        if (preg_match($pattern, $source, $matches) !== 1) {
            return ['(no '.$constant.' declaration found)'];
        }

        preg_match_all('/"([^"]*)"/', $matches[1], $strings);

        return $strings[1];
    }

    public function test_every_definition_is_referenced(): void
    {
        $raw = json_encode(FieldSchemaFixture::schema(), JSON_UNESCAPED_SLASHES);
        $this->assertIsString($raw);

        foreach (array_keys(FieldSchemaFixture::schema()['$defs']) as $name) {
            $this->assertStringContainsString(
                '#/$defs/'.$name,
                $raw,
                'Definition "'.$name.'" is declared but never referenced.',
            );
        }
    }
}
