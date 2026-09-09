<?php

declare(strict_types=1);

namespace Tests\Unit\Preparation\Schema;

use App\Domain\Preparation\Schema\FieldSchemaDocument;
use App\Domain\Preparation\Schema\FieldSchemaValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\FieldSchemaFixture;

/**
 * Every numeric member the published contract admits, probed at its boundary, in this projection.
 *
 * The twin of `resources/js/schema/numericBounds.test.ts`: same members, same probes, same
 * expectations. Two properties are enforced here, and the pair is the point.
 *
 * **The member list is derived from `field-schema-1.1.json`, never written by hand.** A hand-kept
 * list is a second source of the same truth, and it drifted: `anchor.resolved.rect` was filed as a
 * legacy `rect` because it *is* one by type, when it arrived in 1.1 and is this schema's own. The
 * file already knows where every number lives, so the sweep asks it instead of asking a person to
 * remember. A member added to the contract without a probe here fails {@see
 * test_every_numeric_member_of_the_contract_is_swept()} until one is written.
 *
 * **The probe is the boundary, not a big number.** The sweep used to test `1e20` only, which looks
 * like the stronger case — a far larger value — and is strictly weaker: it is a float, so it took
 * the float path and never reached the integer one the bound actually guards, and PHP accepted
 * `2^53` for three properties while TypeScript refused it. A bound is distinguished from its
 * absence by exactly two values: the largest accepted and the smallest refused. `1e20` is kept as
 * a third, looser case.
 *
 * A member with no `maximum` is swept too, with the expectation written down, so the six that are
 * deliberately unbounded (issue #105) cannot quietly become five or seven.
 */
final class NumericBoundsSweepTest extends TestCase
{
    /** Where a probe goes when the path runs through an anchor: the fixture's anchored field. */
    private const ANCHORED_FIELD = 5;

    private const PLAIN_FIELD = 0;

    /**
     * Every numeric member of the contract, as a document path, with its declared bounds.
     *
     * @return array<string, array{type: string, maximum: int|float|null}>
     */
    public static function contractMembers(): array
    {
        $schema = FieldSchemaFixture::schema();
        $defs = $schema['$defs'];
        $found = [];

        $walk = function (array $node, string $path, array $seen) use (&$walk, $defs, &$found): void {
            if (isset($node['$ref'])) {
                $name = substr((string) $node['$ref'], strlen('#/$defs/'));

                if (! in_array($name, $seen, true)) {
                    $walk($defs[$name], $path, [...$seen, $name]);
                }

                return;
            }

            foreach (['oneOf', 'anyOf', 'allOf'] as $combinator) {
                foreach ($node[$combinator] ?? [] as $branch) {
                    $walk($branch, $path, $seen);
                }
            }

            $type = $node['type'] ?? null;

            if ($type === 'number' || $type === 'integer') {
                $found[$path] = [
                    'type' => $type,
                    'minimum' => $node['minimum'] ?? null,
                    'exclusiveMinimum' => $node['exclusiveMinimum'] ?? null,
                    'maximum' => $node['maximum'] ?? null,
                ];

                return;
            }

            if ($type === 'array' && isset($node['items'])) {
                $walk($node['items'], $path.'[]', $seen);

                return;
            }

            foreach ($node['properties'] ?? [] as $key => $child) {
                $walk($child, $path === '' ? (string) $key : $path.'.'.$key, $seen);
            }
        };

        $walk($schema, '', []);
        ksort($found);

        return $found;
    }

    public function test_every_numeric_member_of_the_contract_is_swept(): void
    {
        $this->assertSame(
            array_keys(self::contractMembers()),
            array_keys(self::expectations()),
            'The contract gained or lost a numeric member. Add it to '.self::class.'::expectations() and to '
                .'resources/js/schema/numericBounds.test.ts, with the bound it is meant to have — a member with '
                .'no probe is a member nobody checked.',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function members(): iterable
    {
        foreach (array_keys(self::expectations()) as $path) {
            yield $path => [$path];
        }
    }

    #[DataProvider('members')]
    public function test_the_member_is_bounded_exactly_where_the_contract_says(string $path): void
    {
        $maximum = self::contractMembers()[$path]['maximum'] ?? null;
        $integer = self::contractMembers()[$path]['type'] === 'integer';

        if ($maximum === null) {
            $this->assertFalse(
                self::refuses($path, 1e20),
                $path.' is documented as unbounded (#105). If that changed, update the table in '
                    .'docs/preparation/field-schema.md and the TypeScript twin.',
            );

            return;
        }

        $step = $integer ? 1 : 0.001;

        $this->assertFalse(self::refuses($path, $maximum), $path.' must accept its own maximum, '.$maximum.'.');
        $this->assertTrue(self::refuses($path, $maximum + $step), $path.' must refuse one step past its maximum.');
        $this->assertTrue(self::refuses($path, 1e20), $path.' must refuse a value past the canonical-form range.');
    }

    /**
     * A value the importer accepts must still be acceptable once it has been stored.
     *
     * The other half of the same idea as the bounds sweep, and the half the bounds sweep
     * structurally cannot reach: it probes the *upper* edge, and this defect lives at the lower
     * one. Import canonicalises to three decimals, so `0.0004` is a positive width as written and
     * a zero one as stored — accepted once, then refused by its own next import with
     * `dimension_not_positive`. A document in that state is a latent corruption dressed as a
     * success, and the digest of a document is what an attestation binds.
     *
     * The probe is one canonical step below the smallest legal value, which is where any
     * disagreement between a constraint and the rounding must show itself. Every numeric member
     * is swept, so a member added later is covered without anyone deciding it needs to be.
     */
    #[DataProvider('members')]
    public function test_the_member_survives_its_own_round_trip(string $path): void
    {
        // One canonical step below the smallest positive value: zero after rounding.
        $document = self::documentWith($path, 0.0004);

        if ((new FieldSchemaValidator)->validate($document)->at(self::pointer($path)) !== []) {
            $this->addToAssertionCount(1);

            return;
        }

        $stored = FieldSchemaDocument::fromArray($document)->toArray();

        $this->assertSame(
            [],
            (new FieldSchemaValidator)->validate($stored)->at(self::pointer($path)),
            $path.' accepts a value it cannot read back: import rounds to three decimals, so what '
                .'was validated is not what was stored. Validate the canonical value, never the '
                .'submitted one.',
        );
    }

    /** True when the validator reports a problem *at this member's own pointer*. */
    private static function refuses(string $path, int|float $value): bool
    {
        $document = self::documentWith($path, $value);

        return (new FieldSchemaValidator)->validate($document)->at(self::pointer($path)) !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public static function documentWith(string $path, int|float|string $value): array
    {
        $document = FieldSchemaFixture::asArray();
        $index = str_starts_with($path, 'fields[].anchor') ? self::ANCHORED_FIELD : self::PLAIN_FIELD;

        if (str_starts_with($path, 'fields[].anchor.resolved')) {
            $document['fields'][$index]['anchor']['resolved'] = self::receipt($document['fields'][$index]['rect']);
        }

        // `tolerance` only means anything in cross_check, and a `replace` receipt's rect is
        // required to be the field's own — which would refuse every probe on `resolved.rect`
        // before its own bound could. The widest legal tolerance isolates the bound under test.
        if (str_starts_with($path, 'fields[].anchor.tolerance') || str_starts_with($path, 'fields[].anchor.resolved.rect')) {
            $document['fields'][$index]['anchor']['placement'] = 'cross_check';
            $document['fields'][$index]['anchor']['tolerance'] = 14400;
        }

        $keys = explode('.', str_replace('fields[].', '', $path));
        $target = &$document['fields'][$index];

        foreach ($keys as $depth => $key) {
            if ($depth === count($keys) - 1) {
                $target[$key] = $value;

                break;
            }

            $target = &$target[$key];
        }

        return $document;
    }

    /**
     * @param  array<string, mixed>  $rect
     * @return array<string, mixed>
     */
    private static function receipt(array $rect): array
    {
        return [
            'document_sha256' => str_repeat('d', 64),
            'page' => 2,
            'occurrence_index' => 1,
            'anchor_rect' => ['x' => 330, 'y' => 622.4, 'width' => 165.6, 'height' => 12],
            'rect' => $rect,
        ];
    }

    public static function pointer(string $path): string
    {
        $index = str_starts_with($path, 'fields[].anchor') ? self::ANCHORED_FIELD : self::PLAIN_FIELD;

        return '/fields/'.$index.'/'.str_replace('.', '/', str_replace('fields[].', '', $path));
    }

    /**
     * The members this sweep knows about. Its *keys* are asserted against the contract; the values
     * are documentation, so a reader sees the intent next to the derived truth.
     *
     * @return array<string, string>
     */
    private static function expectations(): array
    {
        return [
            'fields[].anchor.occurrence' => 'bounded: an integer both languages agree on',
            'fields[].anchor.offset.dx' => 'unbounded, 1.0 legacy (#105)',
            'fields[].anchor.offset.dy' => 'unbounded, 1.0 legacy (#105)',
            'fields[].anchor.resolved.anchor_rect.height' => 'bounded by the largest page side',
            'fields[].anchor.resolved.anchor_rect.width' => 'bounded by the largest page side',
            'fields[].anchor.resolved.anchor_rect.x' => 'bounded by the largest page side',
            'fields[].anchor.resolved.anchor_rect.y' => 'bounded by the largest page side',
            'fields[].anchor.resolved.occurrence_index' => 'bounded: an integer both languages agree on',
            'fields[].anchor.resolved.page' => 'bounded: an integer both languages agree on',
            'fields[].anchor.resolved.rect.height' => 'bounded by the largest page side',
            'fields[].anchor.resolved.rect.width' => 'bounded by the largest page side',
            'fields[].anchor.resolved.rect.x' => 'bounded by the largest page side',
            'fields[].anchor.resolved.rect.y' => 'bounded by the largest page side',
            'fields[].anchor.tolerance' => 'bounded by the largest page side',
            'fields[].page' => 'bounded: an integer both languages agree on',
            'fields[].rect.height' => 'unbounded, 1.0 legacy (#105)',
            'fields[].rect.width' => 'unbounded, 1.0 legacy (#105)',
            'fields[].rect.x' => 'unbounded, 1.0 legacy (#105)',
            'fields[].rect.y' => 'unbounded, 1.0 legacy (#105)',
        ];
    }
}
