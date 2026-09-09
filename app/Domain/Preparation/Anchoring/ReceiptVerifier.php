<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Anchoring;

use App\Domain\Preparation\Schema\AnchorPlacement;
use App\Domain\Preparation\Schema\AnchorPlacementMode;
use App\Domain\Preparation\Schema\CanonicalNumber;
use App\Domain\Preparation\Schema\FieldDefinition;
use App\Domain\Preparation\Schema\MeasuredRect;
use App\Domain\Preparation\Schema\ResolvedAnchorRecord;
use App\Domain\Preparation\Text\AnchorOrigin;
use App\Domain\Preparation\Text\AnchorResolver;

/**
 * Whether a resolution receipt is one {@see AnchorResolver} could
 * have produced for this request.
 *
 * These checks used to live in `FieldSchemaValidator`, and they did not work there. A receipt is
 * the output of a computation, and "could the resolver have produced this?" has no referent in a
 * component that does not contain the resolver: five successive review rounds each found another
 * property of that output the validator did not know it should assert, because there was nothing
 * to check the assertions against. Here the question is answerable by asking — and it is asked, by
 * `ReceiptVerifierTest`, which runs the real resolver over generated requests and requires its
 * output to satisfy every rule below and every mutation of that output to break one.
 *
 * ## What a receipt has to satisfy
 *
 * 1. **The page and the occurrence are the ones asked for.** An anchor is searched on the page its
 *    field declares, so a receipt for another page answers a question this field is not asking.
 *    `"sole"` means the text occurs once, so the match taken is always the first.
 * 2. **The extents are the field's own.** An anchor says where a field goes and never how big it
 *    is: `Anchor::$width` and `$height` come from the field's rectangle, so the resolved rectangle
 *    carries them unchanged. A receipt of another size records a resolution that did not happen.
 * 3. **The corner is the requested corner of the matched text, plus the offset.** This is the whole
 *    geometry — `AnchorResolver::build()` picks a corner of `anchor_rect` by `origin` and adds
 *    `offset` — and it is the rule that makes the other two more than bookkeeping: without it a
 *    receipt can pair any measurement with any placement and still look consistent.
 * 4. **In `replace` mode the field's rectangle is the receipt's.** The receipt records where the
 *    field went, so a document whose field sits somewhere its own receipt does not describe is one
 *    nobody can check.
 *
 * Every comparison is made on canonical values, because canonical values are what the document
 * holds; comparing what a caller wrote would accept a receipt that stops agreeing the moment it is
 * stored.
 */
final readonly class ReceiptVerifier
{
    /**
     * Every way this receipt fails to answer this request, as pointer-suffixed reasons.
     *
     * @return list<array{path: string, reason: string}>
     */
    public function problems(FieldDefinition $field, AnchorPlacement $request, ResolvedAnchorRecord $receipt): array
    {
        $problems = [];

        if ($receipt->page !== $field->page) {
            $problems[] = [
                'path' => '/page',
                'reason' => 'records page '.$receipt->page.' and the field is on page '.$field->page,
            ];
        }

        $occurrence = $request->occurrence->isSole() ? 1 : (int) $request->occurrence->index;

        if ($receipt->occurrenceIndex !== $occurrence) {
            $problems[] = [
                'path' => '/occurrence_index',
                'reason' => 'records match '.$receipt->occurrenceIndex.' and the anchor asks for '.$occurrence,
            ];
        }

        foreach (['width' => $field->rect->width, 'height' => $field->rect->height] as $name => $expected) {
            $actual = $name === 'width' ? $receipt->rect->width : $receipt->rect->height;

            if (! self::same($expected, $actual)) {
                $problems[] = [
                    'path' => '/rect/'.$name,
                    'reason' => 'records a '.$name.' of '.$actual.' and the field is '.$expected
                        .'; an anchor decides where a field goes, never how big it is',
                ];
            }
        }

        $problems = array_merge($problems, $this->geometryProblems($request, $receipt));

        if ($request->mode() === AnchorPlacementMode::Replace) {
            foreach (['x' => $field->rect->x, 'y' => $field->rect->y] as $name => $expected) {
                $actual = $name === 'x' ? $receipt->rect->x : $receipt->rect->y;

                if (! self::same($expected, $actual)) {
                    $problems[] = [
                        'path' => '/rect/'.$name,
                        'reason' => 'records '.$name.' '.$actual.' and the field is placed at '.$expected
                            .'; in "replace" mode the receipt records where the field went',
                    ];
                }
            }
        }

        return $problems;
    }

    /**
     * The corner rule: `rect` is the requested corner of `anchor_rect`, displaced by `offset`.
     *
     * @return list<array{path: string, reason: string}>
     */
    private function geometryProblems(AnchorPlacement $request, ResolvedAnchorRecord $receipt): array
    {
        [$originX, $originY] = self::corner($receipt->anchorRect, $request->originCorner());

        $expected = [
            'x' => CanonicalNumber::round($originX + $request->offsetX()),
            'y' => CanonicalNumber::round($originY + $request->offsetY()),
        ];

        $problems = [];

        foreach ($expected as $name => $value) {
            $actual = $name === 'x' ? $receipt->rect->x : $receipt->rect->y;

            if (! self::same($value, $actual)) {
                $problems[] = [
                    'path' => '/rect/'.$name,
                    'reason' => 'records '.$name.' '.$actual.', and the '.$request->originCorner()->value
                        .' corner of the matched text plus the stated offset is '.$value,
                ];
            }
        }

        return $problems;
    }

    /**
     * @return array{float, float}
     */
    private static function corner(MeasuredRect $rect, AnchorOrigin $origin): array
    {
        return match ($origin) {
            AnchorOrigin::TopLeft => [$rect->x, $rect->y],
            AnchorOrigin::TopRight => [$rect->x + $rect->width, $rect->y],
            AnchorOrigin::BottomLeft => [$rect->x, $rect->y + $rect->height],
            AnchorOrigin::BottomRight => [$rect->x + $rect->width, $rect->y + $rect->height],
        };
    }

    private static function same(float $expected, float $actual): bool
    {
        return abs(CanonicalNumber::round($expected) - CanonicalNumber::round($actual)) <= CanonicalNumber::TOLERANCE;
    }
}
