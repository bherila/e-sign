<?php

declare(strict_types=1);

namespace Tests\Unit\Signing;

use App\Domain\Preparation\Schema\FieldDefinition;
use App\Domain\Preparation\Schema\FieldType;
use App\Domain\Preparation\Schema\Rect;
use App\Domain\Signing\Exceptions\FieldSubmissionRejected;
use App\Domain\Signing\Fields\FieldValueValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Only that a value is the right *kind of thing* for its field's type.
 *
 * The boundary matters: this never adopts a signature or decides that a canvas represents
 * intent. That is issue #26, and docs/HANDOFF.md section 8 is explicit that a signature is
 * not recorded merely because a canvas is non-empty.
 */
class FieldValueValidatorTest extends TestCase
{
    /**
     * @return list<array{FieldType, mixed}>
     */
    public static function accepted(): array
    {
        return [
            [FieldType::Checkbox, true],
            [FieldType::Checkbox, false],
            [FieldType::Text, 'Some agreed wording'],
            [FieldType::Name, 'Example Signer'],
            [FieldType::AgreementDate, '2026-01-31'],
            [FieldType::SigningDate, '2028-02-29'],
            [FieldType::Signature, 'data:image/png;base64,AAAA'],
        ];
    }

    /**
     * @return list<array{FieldType, mixed}>
     */
    public static function refused(): array
    {
        return [
            'a checkbox is not a string' => [FieldType::Checkbox, 'true'],
            'a checkbox is not an integer' => [FieldType::Checkbox, 1],
            'text is not an array' => [FieldType::Text, ['a', 'b']],
            'an empty string is not a completed field' => [FieldType::Text, ''],
            'a date needs a date' => [FieldType::AgreementDate, 'the 31st'],
            'a date is not a timestamp' => [FieldType::AgreementDate, '2026-01-31T00:00:00Z'],
            'a date must exist' => [FieldType::AgreementDate, '2026-02-30'],
            'a signature is not a number' => [FieldType::Signature, 42],
            'a signature is not null' => [FieldType::Signature, null],
        ];
    }

    #[DataProvider('accepted')]
    public function test_it_accepts_a_value_of_the_declared_type(FieldType $type, mixed $value): void
    {
        $this->assertSame($value, FieldValueValidator::normalize($this->field($type), $value));
    }

    #[DataProvider('refused')]
    public function test_it_refuses_a_value_of_the_wrong_shape(FieldType $type, mixed $value): void
    {
        try {
            FieldValueValidator::normalize($this->field($type), $value);
            $this->fail('Expected '.json_encode($value).' to be refused for a '.$type->value.'.');
        } catch (FieldSubmissionRejected $e) {
            $this->assertSame('invalid_value', $e->code());
            $this->assertSame('a_field', $e->fieldId);
        }
    }

    public function test_text_is_bounded(): void
    {
        $this->expectException(FieldSubmissionRejected::class);

        FieldValueValidator::normalize(
            $this->field(FieldType::Text),
            str_repeat('a', FieldValueValidator::MAX_TEXT_LENGTH + 1),
        );
    }

    /** A captured signature is much larger than a text field, and still bounded. */
    public function test_a_signature_has_its_own_larger_bound(): void
    {
        $long = str_repeat('a', FieldValueValidator::MAX_TEXT_LENGTH + 1);

        $this->assertSame($long, FieldValueValidator::normalize($this->field(FieldType::Signature), $long));
    }

    private function field(FieldType $type): FieldDefinition
    {
        return new FieldDefinition(
            'a_field',
            'a_recipient',
            $type,
            1,
            new Rect(10, 10, 100, 20),
        );
    }
}
