<?php

declare(strict_types=1);

namespace Tests\Unit\Signing;

use App\Domain\Preparation\Schema\FieldType;
use App\Domain\Signing\Fields\FieldMateriality;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The classification invariant 4 rests on, pinned type by type.
 *
 * Every declared field type appears exactly once here on purpose: adding a type to the
 * schema without deciding whether it is part of the agreement's shared content would
 * otherwise default it to material or signer-specific by accident, and one of those choices
 * silently lets a later signer edit contract text.
 */
class FieldMaterialityTest extends TestCase
{
    /**
     * @return list<array{FieldType, bool}>
     */
    public static function classifications(): array
    {
        return [
            [FieldType::Signature, true],
            [FieldType::Initials, true],
            [FieldType::Name, true],
            [FieldType::Title, true],
            [FieldType::Company, true],
            [FieldType::SigningDate, true],
            [FieldType::Text, false],
            [FieldType::Checkbox, false],
            [FieldType::AgreementDate, false],
        ];
    }

    #[DataProvider('classifications')]
    public function test_each_field_type_is_classified(FieldType $type, bool $signerSpecific): void
    {
        $this->assertSame($signerSpecific, FieldMateriality::isSignerSpecific($type));
        $this->assertSame(! $signerSpecific, FieldMateriality::isMaterial($type));
    }

    public function test_every_declared_type_is_covered(): void
    {
        $covered = array_map(
            static fn (array $case): string => $case[0]->value,
            self::classifications(),
        );

        $this->assertEqualsCanonicalizing(FieldType::values(), $covered);
    }

    public function test_only_the_signing_date_is_supplied_by_the_service(): void
    {
        foreach (FieldType::cases() as $type) {
            $this->assertSame(
                $type === FieldType::SigningDate,
                FieldMateriality::isServiceSupplied($type),
                $type->value.' is misclassified as service-supplied.',
            );
        }
    }

    /**
     * The agreement's effective date sits next to a signature and is not signer-specific:
     * docs/preparation/field-schema.md defines it as set by the sender and identical for
     * every recipient.
     */
    public function test_the_agreement_date_is_material_but_the_signing_date_is_not(): void
    {
        $this->assertTrue(FieldMateriality::isMaterial(FieldType::AgreementDate));
        $this->assertFalse(FieldMateriality::isMaterial(FieldType::SigningDate));
    }
}
