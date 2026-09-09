<?php

declare(strict_types=1);

namespace Tests\Unit\Preparation\Anchoring;

use App\Domain\Preparation\Anchoring\ReceiptVerifier;
use App\Domain\Preparation\Schema\AnchorPlacement;
use App\Domain\Preparation\Schema\AnchorPlacementMode;
use App\Domain\Preparation\Schema\FieldDefinition;
use App\Domain\Preparation\Schema\ResolvedAnchorRecord;
use App\Domain\Preparation\TcPdf\TcPdfTextLocator;
use App\Domain\Preparation\Text\AnchorOrigin;
use App\Domain\Preparation\Text\AnchorResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\PdfFixtures;

/**
 * The verifier is checked against the resolver, because the resolver is what it describes.
 *
 * These rules were written five times in `FieldSchemaValidator`, once per review round, each time
 * adding the property the last round had found missing. That is what happens when a component
 * asserts things about the output of a computation it does not contain: nothing can say whether
 * the assertions are complete, so completeness is discovered one defect at a time.
 *
 * Here the question has a referent. Both cases below run the **real** resolver over the fixture
 * matrix and require:
 *
 * - every receipt it produces to satisfy every rule, and
 * - every single-value mutation of that receipt to break at least one.
 *
 * The second half is what makes the first mean something. A verifier that returns no problems for
 * everything passes the first and fails the second, and a rule that is missing shows up as a
 * mutation nothing catches.
 *
 * The space is `occurrence × origin × offset × placement × page`, and it is swept rather than
 * sampled, because five green instances of a product space say nothing about the product.
 */
final class ReceiptVerifierTest extends TestCase
{
    private const DIGEST = 'aa11bb22cc33dd44ee55ff66007788990011223344556677889900aabbccddee';

    /**
     * Every combination the resolver can be asked for, over the geometry matrix.
     *
     * @return iterable<string, array{string, int, string|int, AnchorOrigin, float, float, AnchorPlacementMode}>
     */
    public static function requests(): iterable
    {
        $geometries = [
            'plain letter page' => ['single-page-letter', 1, 'Signature:', 'sole'],
            'rotated a quarter turn' => ['rotated-pages', 1, 'Signature:', 'sole'],
            'rotated half a turn' => ['rotated-pages', 2, 'Signature:', 'sole'],
            'offset crop box' => ['cropbox-offset', 1, 'Signature:', 'sole'],
            'the second of three' => ['multi-occurrence', 1, 'Signature:', 2],
        ];

        $offsets = ['no offset' => [0.0, 0.0], 'offset down' => [0.0, 12.5], 'offset up and left' => [-8.25, -4.5]];

        foreach ($geometries as $geometry => [$fixture, $page, $text, $occurrence]) {
            foreach (AnchorOrigin::cases() as $origin) {
                foreach ($offsets as $label => [$dx, $dy]) {
                    foreach (AnchorPlacementMode::cases() as $placement) {
                        yield $geometry.', '.$origin->value.', '.$label.', '.$placement->value => [
                            $fixture, $page, $text, $occurrence, $origin, $dx, $dy, $placement,
                        ];
                    }
                }
            }
        }
    }

    #[DataProvider('requests')]
    public function test_the_resolvers_own_output_satisfies_every_rule(
        string $fixture,
        int $page,
        string $text,
        string|int $occurrence,
        AnchorOrigin $origin,
        float $dx,
        float $dy,
        AnchorPlacementMode $placement,
    ): void {
        [$field, $request, $receipt] = $this->resolve($fixture, $page, $text, $occurrence, $origin, $dx, $dy, $placement);

        $this->assertSame(
            [],
            (new ReceiptVerifier)->problems($field, $request, $receipt),
            'The verifier rejected a receipt the resolver itself produced.',
        );
    }

    /**
     * And every mutation of a real receipt breaks a rule — wherever it *can* be caught.
     *
     * One value at a time, so a failure names the rule that caught it. A mutation nothing catches
     * is a rule nobody wrote — except where the mutated value does not reach the placement at all,
     * which is a structural limit rather than a gap, and the limit is stated rather than skipped.
     *
     * `anchor_rect` is a *measurement*, and only the corner named by `origin` feeds the resolved
     * rectangle. So widening the matched text moves nothing unless the origin is on its right edge,
     * and heightening it moves nothing unless the origin is on its bottom. A receipt can therefore
     * misreport the size of the text it matched, in those combinations, and the request alone
     * cannot tell: checking it would need the document, which is the resolver's input and not the
     * receipt's. That is worth knowing rather than hiding — it is the one thing a receipt asserts
     * that nothing downstream can verify.
     *
     * The expectation is therefore per mutation *and* per origin, which is the combination that
     * matters: asserting only "caught somewhere" would pass even if the rule fired for one origin
     * and nothing else.
     *
     * @return iterable<string, array{callable(array<string, mixed>): array<string, mixed>, list<string>}>
     */
    public static function mutations(): iterable
    {
        $always = array_map(static fn (AnchorOrigin $o): string => $o->value, AnchorOrigin::cases());

        yield 'the page it was found on' => [static function (array $r): array {
            $r['page']++;

            return $r;
        }, $always];
        yield 'which match was taken' => [static function (array $r): array {
            $r['occurrence_index']++;

            return $r;
        }, $always];
        yield 'the width of the placement' => [static function (array $r): array {
            $r['rect']['width'] += 1;

            return $r;
        }, $always];
        yield 'the height of the placement' => [static function (array $r): array {
            $r['rect']['height'] += 1;

            return $r;
        }, $always];
        yield 'where the placement sits horizontally' => [static function (array $r): array {
            $r['rect']['x'] += 1;

            return $r;
        }, $always];
        yield 'where the placement sits vertically' => [static function (array $r): array {
            $r['rect']['y'] += 1;

            return $r;
        }, $always];
        yield 'where the text was measured horizontally' => [static function (array $r): array {
            $r['anchor_rect']['x'] += 1;

            return $r;
        }, $always];
        yield 'where the text was measured vertically' => [static function (array $r): array {
            $r['anchor_rect']['y'] += 1;

            return $r;
        }, $always];
        // Only reaches the placement through a right-hand origin.
        yield 'how wide the matched text was' => [static function (array $r): array {
            $r['anchor_rect']['width'] += 1;

            return $r;
        }, [AnchorOrigin::TopRight->value, AnchorOrigin::BottomRight->value]];
        // Only reaches the placement through a bottom origin.
        yield 'how tall the matched text was' => [static function (array $r): array {
            $r['anchor_rect']['height'] += 1;

            return $r;
        }, [AnchorOrigin::BottomLeft->value, AnchorOrigin::BottomRight->value]];
    }

    /**
     * @param  list<string>  $caughtForOrigins
     */
    #[DataProvider('mutations')]
    public function test_every_mutation_of_a_real_receipt_breaks_a_rule(callable $mutate, array $caughtForOrigins): void
    {
        $unexpected = [];

        foreach (self::requests() as $name => [$fixture, $page, $text, $occurrence, $origin, $dx, $dy, $placement]) {
            [$field, $request, $receipt] = $this->resolve($fixture, $page, $text, $occurrence, $origin, $dx, $dy, $placement);

            $mutated = ResolvedAnchorRecord::fromArray($mutate($receipt->toArray()));
            $caught = (new ReceiptVerifier)->problems($field, $request, $mutated) !== [];
            $shouldCatch = in_array($origin->value, $caughtForOrigins, true);

            if ($caught !== $shouldCatch) {
                $unexpected[] = ($shouldCatch ? 'missed: ' : 'caught unexpectedly: ').$name;
            }
        }

        $this->assertSame([], $unexpected);
    }

    /**
     * A receipt is stored component-by-component, and reconstructing it re-adds them.
     *
     * `anchor_rect` is canonicalised per component before storage, while `rect` is canonicalised
     * *after* the corner and the offset are added. So the value the receipt records and the value
     * this verifier reconstructs from the stored components can legitimately sit one canonical
     * step apart — and subtracting two three-decimal values can land a few ulps above that step.
     * Comparing raw therefore rejects the resolver's own correct output.
     *
     * The combination that matters is *which corner* × *how far the reconstruction drifts*, so the
     * matrix walks every origin at the drift the rounding actually produces. A single origin would
     * pass while the right-hand ones failed, which is exactly the shape the reported case had.
     *
     * @return iterable<string, array{AnchorOrigin, float, float, float, float}>
     */
    public static function componentRounding(): iterable
    {
        // Values chosen so each component rounds one way and the sum rounds the other.
        foreach (AnchorOrigin::cases() as $origin) {
            yield $origin->value => [$origin, 541.50045, 622.40055, 97.61745, 11.60055];
        }
    }

    #[DataProvider('componentRounding')]
    public function test_a_receipt_survives_being_stored_component_by_component(
        AnchorOrigin $origin,
        float $x,
        float $y,
        float $width,
        float $height,
    ): void {
        $request = AnchorPlacement::fromArray([
            'text' => 'Signature:',
            'occurrence' => 'sole',
            'placement' => AnchorPlacementMode::Replace->value,
            'origin' => $origin->value,
            'offset' => ['dx' => -100, 'dy' => -100],
        ]);

        // The resolver's own arithmetic: the corner of the *raw* measurement, plus the offset,
        // canonicalised once at the end.
        $cornerX = in_array($origin, [AnchorOrigin::TopRight, AnchorOrigin::BottomRight], true) ? $x + $width : $x;
        $cornerY = in_array($origin, [AnchorOrigin::BottomLeft, AnchorOrigin::BottomRight], true) ? $y + $height : $y;

        $receipt = ResolvedAnchorRecord::fromArray([
            'document_sha256' => self::DIGEST,
            'page' => 1,
            'occurrence_index' => 1,
            'anchor_rect' => ['x' => $x, 'y' => $y, 'width' => $width, 'height' => $height],
            'rect' => ['x' => $cornerX - 100, 'y' => $cornerY - 100, 'width' => 170, 'height' => 36],
        ]);

        $field = FieldDefinition::fromArray([
            'id' => 'signature',
            'recipient_id' => 'signer',
            'type' => 'signature',
            'page' => 1,
            'rect' => $receipt->rect->toArray(),
            'required' => true,
            'read_only' => false,
        ])->withAnchor($request);

        $this->assertSame(
            [],
            (new ReceiptVerifier)->problems($field, $request, $receipt),
            'A receipt whose components round one way and whose sum rounds the other was rejected.',
        );
    }

    /**
     * @return array{FieldDefinition, AnchorPlacement, ResolvedAnchorRecord}
     */
    private function resolve(
        string $fixture,
        int $page,
        string $text,
        string|int $occurrence,
        AnchorOrigin $origin,
        float $dx,
        float $dy,
        AnchorPlacementMode $placement,
    ): array {
        $runs = (new TcPdfTextLocator)->extract(PdfFixtures::bytes($fixture));

        $anchor = AnchorPlacement::fromArray([
            'text' => $text,
            'occurrence' => $occurrence,
            'placement' => $placement->value,
            'origin' => $origin->value,
            'offset' => ['dx' => $dx, 'dy' => $dy],
        ] + ($placement === AnchorPlacementMode::CrossCheck ? ['tolerance' => 14400] : []));

        $field = FieldDefinition::fromArray([
            'id' => 'signature',
            'recipient_id' => 'signer',
            'type' => 'signature',
            'page' => $page,
            'rect' => ['x' => 10, 'y' => 10, 'width' => 170, 'height' => 36],
            'required' => true,
            'read_only' => false,
        ]);

        $resolved = (new AnchorResolver)->resolve(
            $runs,
            $anchor->toAnchor($page, $field->rect->width, $field->rect->height),
        )[0];

        $receipt = ResolvedAnchorRecord::fromResolvedAnchor($resolved, self::DIGEST);

        // In `replace` mode the field's rectangle *is* the resolved one, which is what the
        // resolver writes back; the verifier checks that relationship, so the field has to carry
        // it here too.
        if ($placement === AnchorPlacementMode::Replace) {
            $field = $field->withAnchor($anchor)->withResolvedAnchor($receipt);
        } else {
            $field = $field->withAnchor($anchor);
        }

        return [$field, $anchor, $receipt];
    }
}
