<?php

declare(strict_types=1);

namespace Tests\Feature\Preparation;

use App\Domain\Preparation\Anchoring\SchemaAnchorResolver;
use App\Domain\Preparation\Schema\AnchorPlacement;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A cross-check tolerance that a deployment sets wrong fails as a deployment mistake.
 *
 * Two independent things can be wrong with it and they fail differently, which is why both are
 * checked here rather than one standing in for the other:
 *
 * - **It may not parse.** `(float) "one"` is `0.0`, and zero is a perfectly legal tolerance — so
 *   casting before validating turns a typo into a silent switch to demanding exact coordinate
 *   matches. That is a change in what the product asserts, not a configuration error, and nobody
 *   would see it until a cross-check that should have passed did not.
 * - **It may parse and be out of range.** A negative value reports every exact match as
 *   `anchor_cross_check_failed`, blaming a document that is right; one above the maximum reaches
 *   `AnchorPlacement::withTolerance()` only when a match *succeeds*, so the deployment looks
 *   healthy until an anchor resolves and then throws at request time.
 *
 * The container is the boundary where both are caught, so it is where they are asserted.
 */
final class AnchorToleranceConfigTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function settings(): iterable
    {
        yield 'the default' => [1.0, true];
        yield 'zero' => [0, true];
        yield 'a numeric string' => ['2.5', true];
        yield 'the largest page side' => [AnchorPlacement::MAX_TOLERANCE, true];

        yield 'a word' => ['one', false];
        yield 'an empty string' => ['', false];
        yield 'negative' => [-1.0, false];
        yield 'past the largest page side' => [AnchorPlacement::MAX_TOLERANCE + 1, false];
    }

    #[DataProvider('settings')]
    public function test_the_configured_tolerance_is_parsed_and_ranged_before_it_is_used(
        mixed $setting,
        bool $legal,
    ): void {
        config(['esign.preparation.anchor_cross_check_tolerance' => $setting]);

        if (! $legal) {
            $this->expectException(InvalidArgumentException::class);
        }

        $resolver = $this->app->make(SchemaAnchorResolver::class);

        $this->assertInstanceOf(SchemaAnchorResolver::class, $resolver);
    }
}
