<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Anchoring;

use App\Domain\Preparation\Schema\AnchorPlacement;
use App\Domain\Preparation\Schema\AnchorPlacementMode;
use App\Domain\Preparation\Schema\CanonicalNumber;
use App\Domain\Preparation\Schema\FieldDefinition;
use App\Domain\Preparation\Schema\FieldSchemaDocument;
use App\Domain\Preparation\Schema\PageSizes;
use App\Domain\Preparation\Schema\ResolvedAnchorRecord;
use App\Domain\Preparation\Schema\ValidationCode;
use App\Domain\Preparation\Text\AmbiguousAnchorException;
use App\Domain\Preparation\Text\Anchor;
use App\Domain\Preparation\Text\AnchorNotFoundException;
use App\Domain\Preparation\Text\AnchorResolutionException;
use App\Domain\Preparation\Text\AnchorResolver;
use App\Domain\Preparation\Text\OccurrenceOutOfRangeException;
use App\Domain\Preparation\Text\ResolvedAnchor;
use App\Domain\Preparation\Text\TextRun;
use InvalidArgumentException;

/**
 * Turns every anchor in a field document into a stored rectangle, or refuses the document.
 *
 * This is the field-schema half of anchor placement. {@see AnchorResolver} owns the semantics —
 * what counts as a match, which match wins, and what an absent or ambiguous one means — and this
 * class owns what that means for a *document*: which fields still need resolving, what the
 * resolved rectangle does to the field, which failures are reported together, and what happens
 * to a field whose optional anchor legitimately is not there.
 *
 * ## The rules it applies
 *
 * 1. **An anchor is searched on the page the field declares.** A field states its page, the page
 *    is checked against the document's page count, and an anchor that could move a field to
 *    another page would turn that statement into a suggestion. Scoping the search is also what
 *    makes "matched twice" mean something a sender can act on.
 * 2. **`replace` writes the rectangle; `cross_check` checks it.** See
 *    {@see AnchorPlacementMode}. In `cross_check` mode the declared rectangle survives untouched
 *    and a disagreement larger than the tolerance is a refusal — never a silent move in either
 *    direction.
 * 3. **A resolved rectangle must land on its page.** An anchored rectangle is built from where
 *    the text turned out to be plus the caller's offset, so nothing before resolution knows
 *    whether it fits. A field a signer cannot reach is refused rather than stored. The *matched
 *    text's* own box is not checked that way and must not be: a heading's ascender routinely
 *    crosses the top of the CropBox, and refusing that would refuse the document rather than the
 *    placement.
 * 4. **Absent is only acceptable when the document says so, and only for an optional field.**
 *    Then the field is *omitted* — removed from the field set and recorded — rather than placed
 *    at its placeholder rectangle. Ambiguity is never acceptable.
 * 5. **Nothing is resolved twice.** A field whose receipt already names these exact bytes is left
 *    alone, so the stored rectangle cannot drift if extraction changes later.
 *
 * Every failure is collected, not thrown at the first one: see {@see AnchorResolutionFailed}.
 */
final readonly class SchemaAnchorResolver
{
    /**
     * Default slack for a `cross_check` anchor, in points, when neither the anchor nor the
     * deployment says otherwise.
     *
     * One point is about a third of a millimetre. It is deliberately small: the point of a
     * cross-check is to catch a layout that moved, and a generous tolerance catches nothing.
     */
    public const DEFAULT_CROSS_CHECK_TOLERANCE = 1.0;

    /** Slack when comparing a resolved rectangle against the page edge. */
    private const PAGE_TOLERANCE = 1.0e-6;

    public function __construct(
        private AnchorResolver $anchors,
        private float $defaultCrossCheckTolerance = self::DEFAULT_CROSS_CHECK_TOLERANCE,
    ) {}

    /**
     * @param  array<int, TextRun>  $runs  Positioned text from the document `$documentSha256` names.
     * @param  string  $documentSha256  Digest of the exact bytes `$runs` came from.
     * @param  bool  $omitAbsentFields  Whether an absent optional anchor removes its field from the
     *                                  returned document, or is only reported. Publishing reports;
     *                                  sending removes. Either way the absence is never a silent
     *                                  placement at the field's placeholder rectangle.
     *
     * @throws AnchorResolutionFailed
     */
    public function resolve(
        FieldSchemaDocument $schema,
        array $runs,
        string $documentSha256,
        ?PageSizes $pageSizes = null,
        bool $omitAbsentFields = true,
    ): AnchorResolutionOutcome {
        /** @var list<AnchorResolutionProblem> $problems */
        $problems = [];
        /** @var list<FieldDefinition> $fields */
        $fields = [];
        /** @var list<string> $resolved */
        $resolved = [];
        /** @var list<AnchorOmission> $omissions */
        $omissions = [];

        foreach ($schema->fields as $index => $field) {
            $anchor = $field->anchor;

            if (! $anchor instanceof AnchorPlacement || ! $field->anchorNeedsResolution($documentSha256)) {
                $fields[] = $field;

                continue;
            }

            $outcome = $this->resolveField($index, $field, $anchor, $runs, $documentSha256, $pageSizes);

            if ($outcome instanceof AnchorResolutionProblem) {
                $problems[] = $outcome;

                // The field is kept in place so the pointers in the remaining problems still
                // address the document the caller sent. Nothing is stored: the whole pass is
                // discarded by the throw below.
                $fields[] = $field;

                continue;
            }

            if ($outcome instanceof AnchorOmission) {
                $omissions[] = $outcome;

                if (! $omitAbsentFields) {
                    $fields[] = $field;
                }

                continue;
            }

            $fields[] = $outcome;
            $resolved[] = $field->id;
        }

        if ($problems !== []) {
            throw new AnchorResolutionFailed($problems);
        }

        $fieldsOmitted = $omitAbsentFields && $omissions !== [];
        $source = hash('sha256', $schema->canonicalJson());

        if ($resolved === [] && ! $fieldsOmitted) {
            return new AnchorResolutionOutcome($schema, [], $omissions, false, $source);
        }

        return new AnchorResolutionOutcome(
            $schema->withFields($fields),
            $resolved,
            $omissions,
            $fieldsOmitted,
            $source,
        );
    }

    /**
     * @param  array<int, TextRun>  $runs
     */
    private function resolveField(
        int $index,
        FieldDefinition $field,
        AnchorPlacement $anchor,
        array $runs,
        string $documentSha256,
        ?PageSizes $pageSizes,
    ): FieldDefinition|AnchorOmission|AnchorResolutionProblem {
        $request = $anchor->toAnchor($field->page, $field->rect->width, $field->rect->height);

        try {
            $matches = $this->anchors->resolve($runs, $request);
        } catch (AnchorResolutionException $failure) {
            return $this->failureProblem($index, $field, $anchor, $request, $runs, $failure);
        }

        if ($matches === []) {
            // Only reachable with `anchor.required: false`, which the validator refuses on a
            // required field. The declared, narrow compatibility option.
            return AnchorOmission::forField($field);
        }

        $found = $matches[0];

        $offPage = $this->offPageProblem($index, $field, $anchor, $found, $pageSizes);

        if ($offPage instanceof AnchorResolutionProblem) {
            return $offPage;
        }

        $mismatch = $this->crossCheckProblem($index, $field, $anchor, $found);

        if ($mismatch instanceof AnchorResolutionProblem) {
            return $mismatch;
        }

        // Storable by construction: the matched text's own box is a measurement and may sit
        // partly outside the page ({@see MeasuredRect}), and the resolved rectangle has already
        // been checked against the page above. The guard stays because a value object that can
        // throw should never be constructed on the assumption that it will not.
        try {
            $record = ResolvedAnchorRecord::fromResolvedAnchor($found, $documentSha256);
        } catch (InvalidArgumentException $unstorable) {
            return new AnchorResolutionProblem(
                $index,
                $field->id,
                $field->recipientId,
                $anchor->text,
                ValidationCode::AnchorResolvedOffPage,
                'a rectangle that cannot be recorded',
                'Field "'.$field->id.'" is anchored to "'.$anchor->text.'" on page '.$field->page
                    .', and the match cannot be recorded: '.$unstorable->getMessage().' This is a property of the '
                    .'matched text itself, not of the offset.',
            );
        }

        // A cross-check judged against the deployment default records that number, so the
        // stored document says what it was checked against rather than leaving it to whatever
        // the configuration says the next time somebody looks.
        if ($anchor->mode() === AnchorPlacementMode::CrossCheck) {
            $field = $field->withAnchor($anchor->withTolerance($this->toleranceFor($anchor)));
        }

        return $field->withResolvedAnchor($record);
    }

    /**
     * @param  array<int, TextRun>  $runs
     */
    private function failureProblem(
        int $index,
        FieldDefinition $field,
        AnchorPlacement $anchor,
        Anchor $request,
        array $runs,
        AnchorResolutionException $failure,
    ): AnchorResolutionProblem {
        $count = count($this->anchors->matches($runs, $request));

        $code = match (true) {
            $failure instanceof AnchorNotFoundException => ValidationCode::AnchorNotFound,
            $failure instanceof AmbiguousAnchorException => ValidationCode::AnchorAmbiguous,
            $failure instanceof OccurrenceOutOfRangeException => ValidationCode::AnchorOccurrenceOutOfRange,
            default => ValidationCode::AnchorNotFound,
        };

        $found = $count === 0
            ? 'no match on page '.$field->page
            : $count.' '.($count === 1 ? 'match' : 'matches').' on page '.$field->page;

        return new AnchorResolutionProblem(
            $index,
            $field->id,
            $field->recipientId,
            $anchor->text,
            $code,
            $found,
            'Field "'.$field->id.'" could not be anchored: '.$failure->getMessage().' Found '.$found
                .'. A field placed at a fallback position is a field nobody agreed to sign there, so nothing '
                .'is placed and nothing is sent.',
        );
    }

    private function offPageProblem(
        int $index,
        FieldDefinition $field,
        AnchorPlacement $anchor,
        ResolvedAnchor $found,
        ?PageSizes $pageSizes,
    ): ?AnchorResolutionProblem {
        $rect = $found->resolvedRect;
        $offPage = $rect->x < -self::PAGE_TOLERANCE || $rect->y < -self::PAGE_TOLERANCE;
        $size = null;

        if (! $offPage && $pageSizes instanceof PageSizes && $pageSizes->has($field->page)) {
            $size = $pageSizes->of($field->page);
            $offPage = $rect->right() > $size['width'] + self::PAGE_TOLERANCE
                || $rect->bottom() > $size['height'] + self::PAGE_TOLERANCE;
        }

        if (! $offPage) {
            return null;
        }

        $where = 'x '.$this->number($rect->x).', y '.$this->number($rect->y)
            .', '.$this->number($rect->width).' by '.$this->number($rect->height).' pt'
            .($size === null ? '' : ' on a page '.$this->number($size['width']).' by '.$this->number($size['height']).' pt');

        return new AnchorResolutionProblem(
            $index,
            $field->id,
            $field->recipientId,
            $anchor->text,
            ValidationCode::AnchorResolvedOffPage,
            $where,
            'Field "'.$field->id.'" anchored to "'.$anchor->text.'" on page '.$field->page
                .', but the offset puts it off the page ('.$where.'). A field a signer cannot reach is refused '
                .'rather than stored.',
        );
    }

    /**
     * The declared rectangle and the resolved one must agree, or the document is wrong somewhere.
     *
     * A `cross_check` anchor is a consumer saying "I generated this layout, the box is here, and
     * the words `X` are next to it". When the two disagree by more than the tolerance, exactly one
     * of those statements is stale and there is no way to tell which — so neither is believed.
     * Trusting the rectangle would stamp a signature next to text that moved; trusting the anchor
     * would move a box the consumer's own layout positions. Both are silent, and this is not.
     */
    private function crossCheckProblem(
        int $index,
        FieldDefinition $field,
        AnchorPlacement $anchor,
        ResolvedAnchor $found,
    ): ?AnchorResolutionProblem {
        if ($anchor->mode() !== AnchorPlacementMode::CrossCheck) {
            return null;
        }

        $tolerance = $this->toleranceFor($anchor);
        $dx = abs($found->resolvedRect->x - $field->rect->x);
        $dy = abs($found->resolvedRect->y - $field->rect->y);

        if ($dx <= $tolerance + self::PAGE_TOLERANCE && $dy <= $tolerance + self::PAGE_TOLERANCE) {
            return null;
        }

        $where = 'x '.$this->number($found->resolvedRect->x).', y '.$this->number($found->resolvedRect->y)
            .' (declared x '.$this->number($field->rect->x).', y '.$this->number($field->rect->y)
            .'; off by '.$this->number($dx).', '.$this->number($dy).' pt)';

        return new AnchorResolutionProblem(
            $index,
            $field->id,
            $field->recipientId,
            $anchor->text,
            ValidationCode::AnchorCrossCheckFailed,
            $where,
            'Field "'.$field->id.'" declares its rectangle and cross-checks it against anchor "'.$anchor->text
                .'", but the two disagree by more than the '.$this->number($tolerance).' pt tolerance: resolved '
                .$where.'. One of them is stale and there is no way to tell which, so neither is used.',
        );
    }

    /** The tolerance a cross-check is judged against: the document's, else the deployment's. */
    private function toleranceFor(AnchorPlacement $anchor): float
    {
        return $anchor->tolerance ?? $this->defaultCrossCheckTolerance;
    }

    private function number(float $value): string
    {
        try {
            return (string) CanonicalNumber::encode(CanonicalNumber::round($value));
        } catch (InvalidArgumentException) {
            return var_export($value, true);
        }
    }
}
