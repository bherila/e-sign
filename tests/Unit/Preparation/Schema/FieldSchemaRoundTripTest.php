<?php

declare(strict_types=1);

namespace Tests\Unit\Preparation\Schema;

use App\Domain\Preparation\Schema\AnchorPlacement;
use App\Domain\Preparation\Schema\CanonicalNumber;
use App\Domain\Preparation\Schema\CoordinateSpaceDeclaration;
use App\Domain\Preparation\Schema\FieldSchemaDocument;
use App\Domain\Preparation\Schema\FieldSchemaValidator;
use App\Domain\Preparation\Schema\FieldType;
use App\Domain\Preparation\Schema\PageSizes;
use App\Domain\Preparation\Text\AnchorOrigin;
use PHPUnit\Framework\TestCase;

/**
 * Property-style round trip: JSON -> model -> JSON with zero coordinate drift (issue #20).
 *
 * A thousand generated documents, each carrying the awkward cases — integral coordinates,
 * three-decimal coordinates, negative anchor offsets, absent optional properties, several
 * signing stages — are imported, exported, and imported again. The assertions are strict
 * identity, not float tolerance: `assertSame` on arrays compares key order and value *type*, so
 * an `x` that came in as JSON `60` and went out as `60.0` fails here, which is exactly the drift
 * that would show up as a signature nudged a hair down the page after an editor session.
 *
 * The generator is seeded, so a failure is reproducible: the seed is in the failure message.
 */
class FieldSchemaRoundTripTest extends TestCase
{
    private const DOCUMENT_COUNT = 1000;

    private const SEED = 20250908;

    private const PAGE_COUNT = 6;

    private const PAGE_WIDTH = 612.0;

    private const PAGE_HEIGHT = 792.0;

    public function test_a_thousand_documents_survive_the_round_trip_byte_identically(): void
    {
        mt_srand(self::SEED);

        for ($index = 0; $index < self::DOCUMENT_COUNT; $index++) {
            $canonical = self::generateDocument($index);
            $context = 'document '.$index.' (seed '.self::SEED.'): '.json_encode($canonical, FieldSchemaDocument::JSON_FLAGS);

            $document = FieldSchemaDocument::fromArray($canonical);

            // JSON -> model -> JSON, key for key, type for type.
            $this->assertSame($canonical, $document->toArray(), $context);

            // ... and again through the encoder, which is where a float would drift.
            $json = $document->canonicalJson();
            $reimported = FieldSchemaDocument::fromJson($json);

            $this->assertSame($json, $reimported->canonicalJson(), $context);
            $this->assertSame($canonical, $reimported->toArray(), $context);
            $this->assertSame($canonical, json_decode($json, true, 64, JSON_THROW_ON_ERROR), $context);
        }
    }

    public function test_generated_documents_validate_against_their_page_geometry(): void
    {
        mt_srand(self::SEED + 1);

        for ($index = 0; $index < 100; $index++) {
            $canonical = self::generateDocument($index);
            $document = FieldSchemaDocument::fromArray($canonical);

            $result = (new FieldSchemaValidator)->validate(
                $canonical,
                PageSizes::uniform(self::PAGE_COUNT, self::PAGE_WIDTH, self::PAGE_HEIGHT),
                ['recipient.name', 'recipient.company', 'recipient.title', 'envelope.agreement_date'],
            );

            $this->assertTrue($result->isValid(), 'document '.$index.': '.$result->describe());
            $this->assertLessThanOrEqual(self::PAGE_COUNT, $document->highestPage());
        }
    }

    public function test_the_three_decimal_rule_is_applied_once_and_then_stable(): void
    {
        $this->assertSame(60.123, CanonicalNumber::round(60.1234));
        $this->assertSame(60.124, CanonicalNumber::round(60.1235));
        $this->assertSame(60.0, CanonicalNumber::round(60.0004));
        $this->assertSame(60, CanonicalNumber::encode(60.0004));
        $this->assertSame(60.001, CanonicalNumber::encode(60.0005));
        $this->assertSame(0, CanonicalNumber::encode(0.0));

        // Idempotence: rounding a rounded value never moves it again.
        foreach ([0.0005, 1.4445, 99.9995, 612.0, 0.001] as $value) {
            $once = CanonicalNumber::round($value);
            $this->assertSame($once, CanonicalNumber::round($once));
        }
    }

    /**
     * A random but always canonical document: property order, explicit flags, and canonical
     * numbers, so `toArray()` must reproduce it exactly.
     *
     * @return array<string, mixed>
     */
    private static function generateDocument(int $index): array
    {
        $recipientCount = mt_rand(1, 4);
        $recipients = [];
        $ids = [];

        for ($i = 0; $i < $recipientCount; $i++) {
            $id = 'r'.$index.'-'.$i;
            $ids[] = $id;

            $recipient = [
                'id' => $id,
                'name' => 'Example Party '.$i,
                'email' => 'party'.$i.'@example.test',
            ];

            if (mt_rand(0, 1) === 1) {
                $recipient['role'] = 'Party '.$i;
            }

            $recipients[] = $recipient;
        }

        $fields = [];
        $fieldCount = mt_rand(0, 8);
        $variables = ['recipient.name', 'recipient.company', 'recipient.title', 'envelope.agreement_date'];

        for ($i = 0; $i < $fieldCount; $i++) {
            $field = [
                'id' => 'f'.$index.'-'.$i,
                'recipient_id' => $ids[mt_rand(0, $recipientCount - 1)],
                'type' => FieldType::values()[mt_rand(0, count(FieldType::values()) - 1)],
                'page' => mt_rand(1, self::PAGE_COUNT),
                'rect' => self::generateRect(),
                'required' => mt_rand(0, 1) === 1,
                'read_only' => mt_rand(0, 1) === 1,
            ];

            if (mt_rand(0, 2) === 0) {
                $field['label'] = 'Field '.$i;
            }

            if (mt_rand(0, 2) === 0) {
                $field['alias'] = 'alias'.$index.'-'.$i;
            }

            if (mt_rand(0, 2) === 0) {
                $field['prefill'] = ['variable' => $variables[mt_rand(0, count($variables) - 1)]];
            }

            if (mt_rand(0, 2) === 0) {
                $anchor = [
                    'text' => 'Anchor '.$i.':',
                    'occurrence' => mt_rand(0, 3) === 0 ? AnchorPlacement::OCCURRENCE_SOLE : mt_rand(1, 4),
                ];

                if (mt_rand(0, 1) === 1) {
                    $anchor['origin'] = AnchorOrigin::cases()[mt_rand(0, count(AnchorOrigin::cases()) - 1)]->value;
                }

                if (mt_rand(0, 1) === 1) {
                    $anchor['offset'] = [
                        'dx' => self::generateNumber(-40.0, 40.0),
                        'dy' => self::generateNumber(-40.0, 40.0),
                    ];
                }

                $field['anchor'] = $anchor;
            }

            $fields[] = $field;
        }

        return [
            'schema_version' => '1.0',
            'document_id' => 'doc'.$index,
            'coordinate_space' => CoordinateSpaceDeclaration::expected(),
            'recipients' => $recipients,
            'signing_order' => self::generateSigningOrder($ids),
            'fields' => $fields,
        ];
    }

    /**
     * @return array{x: int|float, y: int|float, width: int|float, height: int|float}
     */
    private static function generateRect(): array
    {
        $width = self::generateNumber(1.0, 200.0, false);
        $height = self::generateNumber(1.0, 60.0, false);
        // Floor the upper bound: rounding to a whole number can move a value up by half a
        // point, and the generated rectangles have to stay on the page for the geometry test.
        $x = self::generateNumber(0.0, floor(self::PAGE_WIDTH - $width), false);
        $y = self::generateNumber(0.0, floor(self::PAGE_HEIGHT - $height), false);

        return [
            'x' => CanonicalNumber::encode($x),
            'y' => CanonicalNumber::encode($y),
            'width' => CanonicalNumber::encode($width),
            'height' => CanonicalNumber::encode($height),
        ];
    }

    /**
     * A canonical number in range: sometimes integral, sometimes at one, two, or three decimals,
     * because those are the spellings that have to survive encoding unchanged.
     */
    private static function generateNumber(float $min, float $max, bool $encode = true): int|float
    {
        $decimals = mt_rand(0, CanonicalNumber::DECIMALS);
        $span = $max - $min;
        $value = round($min + ($span * (mt_rand(0, 1_000_000) / 1_000_000)), $decimals);

        return $encode ? CanonicalNumber::encode($value) : $value;
    }

    /**
     * Every recipient in exactly one stage, with a random number of stages.
     *
     * @param  list<string>  $ids
     * @return list<list<string>>
     */
    private static function generateSigningOrder(array $ids): array
    {
        $stages = [];
        $remaining = $ids;

        while ($remaining !== []) {
            $take = mt_rand(1, count($remaining));
            $stages[] = array_values(array_splice($remaining, 0, $take));
        }

        return $stages;
    }
}
