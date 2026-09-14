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
 * 2. **The extents are the field's own, as submitted.** An anchor says where a field goes and never
 *    how big it is: `Anchor::$width` and `$height` come from the field's rectangle, so the resolved
 *    rectangle carries them unchanged. A receipt of another size records a resolution that did not
 *    happen. Judged against the field *before* it was placed: in `replace` mode the placed field's
 *    rectangle is copied from the receipt, so comparing those two would compare the receipt with
 *    itself and accept any size (#120).
 * 3. **The corner is the requested corner of the matched text, plus the offset.** This is the whole
 *    geometry — `AnchorResolver::build()` picks a corner of `anchor_rect` by `origin` and adds
 *    `offset` — and it is the rule that makes the other two more than bookkeeping: without it a
 *    receipt can pair any measurement with any placement and still look consistent.
 * 4. **The placed field sits where its mode says.** In `replace` mode its rectangle is the
 *    receipt's: the receipt records where the field went, so a field sitting somewhere its own
 *    receipt does not describe is one nobody can check. In `cross_check` mode it is the declared
 *    rectangle, untouched, because a check promises to move nothing.
 *
 * Every comparison is made on canonical values, because canonical values are what the document
 * holds; comparing what a caller wrote would accept a receipt that stops agreeing the moment it is
 * stored.
 *
 * Not final. The resolver's response to a verifier that *does* find a contradiction — a server
 * defect, never a refusal — can only be tested with a verifier that finds one, and the real
 * resolver never gives it cause.
 */
readonly class ReceiptVerifier
{
    /**
     * Every way this receipt, and the field placed from it, fail to answer this request, as
     * pointer-suffixed reasons.
     *
     * @param  FieldDefinition  $submitted  The field as it arrived, before resolution placed it.
     * @param  FieldDefinition  $placed  The same field with the receipt written onto it.
     * @return list<array{path: string, reason: string}>
     */
    public function problems(FieldDefinition $submitted, FieldDefinition $placed, AnchorPlacement $request, ResolvedAnchorRecord $receipt): array
    {
        $problems = [];

        if ($receipt->page !== $submitted->page) {
            $problems[] = [
                'path' => '/page',
                'reason' => 'records page '.$receipt->page.' and the field is on page '.$submitted->page,
            ];
        }

        $occurrence = $request->occurrence->isSole() ? 1 : (int) $request->occurrence->index;

        if ($receipt->occurrenceIndex !== $occurrence) {
            $problems[] = [
                'path' => '/occurrence_index',
                'reason' => 'records match '.$receipt->occurrenceIndex.' and the anchor asks for '.$occurrence,
            ];
        }

        foreach (['width' => $submitted->rect->width, 'height' => $submitted->rect->height] as $name => $expected) {
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

        return array_merge($problems, $this->placementProblems($submitted, $placed, $request, $receipt));
    }

    /**
     * Rule 4: the placed field is the receipt's rectangle in `replace` mode, and the declared one in
     * `cross_check` mode — every component, the extents included.
     *
     * @return list<array{path: string, reason: string}>
     */
    private function placementProblems(
        FieldDefinition $submitted,
        FieldDefinition $placed,
        AnchorPlacement $request,
        ResolvedAnchorRecord $receipt,
    ): array {
        $replaces = $request->mode() === AnchorPlacementMode::Replace;
        $problems = [];

        $expected = $replaces
            ? ['x' => $receipt->rect->x, 'y' => $receipt->rect->y, 'width' => $receipt->rect->width, 'height' => $receipt->rect->height]
            : ['x' => $submitted->rect->x, 'y' => $submitted->rect->y, 'width' => $submitted->rect->width, 'height' => $submitted->rect->height];

        $actual = ['x' => $placed->rect->x, 'y' => $placed->rect->y, 'width' => $placed->rect->width, 'height' => $placed->rect->height];

        foreach ($expected as $name => $value) {
            if (self::same($value, $actual[$name])) {
                continue;
            }

            $problems[] = [
                'path' => '/rect/'.$name,
                'reason' => $replaces
                    ? 'records '.$name.' '.$value.' and the field is placed at '.$actual[$name]
                        .'; in "replace" mode the receipt records where the field went'
                    : 'leaves the field at '.$name.' '.$actual[$name].' and it was declared at '.$value
                        .'; a cross-check never moves the field it checks',
            ];
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

    /**
     * Equal once both sides are canonical, and once the *difference* is too.
     *
     * Rounding both operands is not enough on its own: two canonical values one step apart can
     * subtract to a few ulps above `0.001`, and two that should be identical can differ by a few
     * ulps below it. Here that matters more than it usually would, because the receipt's
     * `anchor_rect` components are each rounded before storage while `rect` is rounded *after* the
     * corner and the offset are added — so the reconstruction below legitimately lands one
     * canonical step from the recorded value, and comparing raw would reject the resolver's own
     * correct output.
     */
    private static function same(float $expected, float $actual): bool
    {
        $delta = CanonicalNumber::round(abs(CanonicalNumber::round($expected) - CanonicalNumber::round($actual)));

        return $delta <= CanonicalNumber::TOLERANCE;
    }
}
