<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Anchoring;

use App\Domain\Preparation\Geometry\NativeRect;
use App\Domain\Preparation\Preflight\PreflightBudget;
use App\Domain\Preparation\Preflight\PreflightBudgetException;
use App\Domain\Preparation\Schema\AnchorPlacement;
use App\Domain\Preparation\Schema\AnchorPlacementMode;
use App\Domain\Preparation\Schema\CanonicalNumber;
use App\Domain\Preparation\Schema\FieldDefinition;
use App\Domain\Preparation\Schema\FieldSchemaDocument;
use App\Domain\Preparation\Schema\MeasuredRect;
use App\Domain\Preparation\Schema\PageSizes;
use App\Domain\Preparation\Schema\ResolvedAnchorRecord;
use App\Domain\Preparation\Schema\ValidationCode;
use App\Domain\Preparation\Text\AmbiguousAnchorException;
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
 * class owns what that means for a *document*: what the resolved rectangle does to the field,
 * which failures are reported together, and what happens to a field whose optional anchor
 * legitimately is not there.
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
 * 5. **A receipt is a record, never a licence to skip.** Every anchored field is resolved on
 *    every pass, including one that already carries a receipt naming these exact bytes. A receipt
 *    binds a rectangle to a document, and to a page and an occurrence, but not to the anchor
 *    *text*, the origin corner, or the offset — so honouring one would let an edited request keep
 *    an answer to the question it used to ask, and a required anchor whose text is no longer in
 *    the document would place silently at the old coordinates instead of failing. Resolution is a
 *    pure function of the request and the bytes: running it again on an unchanged pair costs one
 *    parse and returns the same rectangle, and running it again on a changed one is the entire
 *    point. Nothing re-resolves *after* send; that is a different rule, and it still holds.
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
        private ReceiptVerifier $receipts = new ReceiptVerifier,
    ) {
        // A deployment's tolerance is held to the same range a document's is, and refused here
        // rather than where it is used. Unvalidated, a negative setting reports every exact match
        // to the caller as `anchor_cross_check_failed` — a 422 blaming a document that is right —
        // and one above the maximum reaches `AnchorPlacement::withTolerance()` when a *successful*
        // match records what it was checked against, throwing at request time. A configuration
        // mistake should fail as a configuration mistake, at the point the object is built.
        if (! is_finite($this->defaultCrossCheckTolerance)
            || $this->defaultCrossCheckTolerance < 0.0
            || $this->defaultCrossCheckTolerance > AnchorPlacement::MAX_TOLERANCE
        ) {
            throw new InvalidArgumentException(
                'esign.preparation.anchor_cross_check_tolerance must be a finite number of points between 0 and '
                    .AnchorPlacement::MAX_TOLERANCE.'; got '.var_export($this->defaultCrossCheckTolerance, true)
                    .'. A cross-check tolerance is a distance on one page, and a negative one refuses every '
                    .'document while a larger one cannot be recorded in the receipt that says what was applied.',
            );
        }

    }

    /**
     * @param  array<int, TextRun>  $runs  Positioned text from the document `$documentSha256` names.
     * @param  string  $documentSha256  Digest of the exact bytes `$runs` came from.
     * @param  bool  $omitAbsentFields  Whether an absent optional anchor removes its field from the
     *                                  returned document, or is only reported. Publishing reports;
     *                                  sending removes. Either way the absence is never a silent
     *                                  placement at the field's placeholder rectangle.
     * @param  PreflightBudget|null  $budget  The same budget extraction was charged against, so
     *                                        the whole of reading one document is bounded by one
     *                                        set of limits. Matching is not free and is not
     *                                        covered by extraction's ceiling: it begins after
     *                                        extraction returns, and it scans every run once per
     *                                        anchored field. A field set has no length limit, so
     *                                        a document within every preflight ceiling can still
     *                                        cost runs x fields here. Null leaves matching
     *                                        unbounded, which is only safe for inputs a test
     *                                        controls.
     *
     * @throws AnchorResolutionFailed
     * @throws PreflightBudgetException
     */
    public function resolve(
        FieldSchemaDocument $schema,
        array $runs,
        string $documentSha256,
        ?PageSizes $pageSizes = null,
        bool $omitAbsentFields = true,
        ?PreflightBudget $budget = null,
    ): AnchorResolutionOutcome {
        /** @var list<AnchorResolutionProblem> $problems */
        $problems = [];
        /** @var list<FieldDefinition> $fields */
        $fields = [];
        /** @var list<string> $resolved */
        $resolved = [];
        /** @var list<AnchorOmission> $omissions */
        $omissions = [];
        /** @var list<string> $cleared Fields kept for reporting whose stale receipt was removed. */
        $cleared = [];

        foreach ($schema->fields as $index => $field) {
            $anchor = $field->anchor;

            if (! $anchor instanceof AnchorPlacement) {
                $fields[] = $field;

                continue;
            }

            // Before the scan this field is about to pay for, not after: a budget checked only
            // afterwards reports the cost of work already done.
            $budget?->tick();

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
                    // Kept, but not with a receipt saying its text was found: this pass looked and
                    // found nothing, and a stale receipt beside that omission would be the
                    // document asserting both. The *request* stays, so resolving again can still
                    // answer it.
                    $withoutReceipt = $anchor->resolved instanceof ResolvedAnchorRecord
                        ? $field->withAnchor($anchor->withoutReceipt())
                        : $field;

                    if ($withoutReceipt !== $field) {
                        $cleared[] = $field->id;
                    }

                    $fields[] = $withoutReceipt;
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

        // `$cleared` counts: a pass that only removed a stale receipt still changed the document,
        // and returning `unchanged` would leave the old receipt in place — the exact claim this
        // pass just disproved.
        if ($resolved === [] && $cleared === [] && ! $fieldsOmitted) {
            return new AnchorResolutionOutcome($schema, [], $omissions, false, $source);
        }

        return new AnchorResolutionOutcome(
            $schema->withFields($fields),
            $resolved,
            $omissions,
            $fieldsOmitted,
            $source,
            $cleared,
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
        // Before anything is looked for. The search is scoped to the field's page, so a field
        // naming a page the document does not have cannot be resolved for a reason that has
        // nothing to do with its text — and letting it fall through would report a *required*
        // anchor as "text not found" and an *optional* one as legitimately absent, quietly
        // dropping the field. Neither describes what is wrong.
        if ($pageSizes instanceof PageSizes && ! $pageSizes->has($field->page)) {
            return new AnchorResolutionProblem(
                $index,
                $field->id,
                $field->recipientId,
                $anchor->text,
                ValidationCode::PageOutOfRange,
                'page '.$field->page.' of a '.$pageSizes->pageCount().'-page document',
                'Field "'.$field->id.'" is anchored to "'.$anchor->text.'" on page '.$field->page
                    .', and the document has '.$pageSizes->pageCount().' pages. An anchor is searched on the page '
                    .'its field declares, so this one could not be looked for at all — which is not the same as '
                    .'its text being absent.',
                member: 'page',
            );
        }

        $request = $anchor->toAnchor($field->page, $field->rect->width, $field->rect->height);

        try {
            $matches = $this->anchors->resolve($runs, $request);
        } catch (AnchorResolutionException $failure) {
            return $this->failureProblem($index, $field, $anchor, $failure);
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

        $unbounded = $this->outOfBoundsProblem($index, $field, $anchor, $found);

        if ($unbounded instanceof AnchorResolutionProblem) {
            return $unbounded;
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
                    .', and the match cannot be recorded: '.$unstorable->getMessage().' The resolved rectangle is '
                    .'checked before this point, so what cannot be recorded here is the measurement of the matched '
                    .'text itself.',
            );
        }

        // A cross-check judged against the deployment default records that number, so the
        // stored document says what it was checked against rather than leaving it to whatever
        // the configuration says the next time somebody looks.
        if ($anchor->mode() === AnchorPlacementMode::CrossCheck) {
            $field = $field->withAnchor($anchor->withTolerance($this->toleranceFor($anchor)));
        }

        $placed = $field->withResolvedAnchor($record);

        // The resolver checks its own answer before returning it. Every rule in
        // {@see ReceiptVerifier} is a property of what this method just computed, so a failure
        // here is this service contradicting itself — not a caller sending something wrong — and
        // it is reported rather than stored. The alternative is a receipt that says a placement
        // was derived from a measurement it was not derived from, which is exactly the kind of
        // thing nobody notices until an agreement is signed against it.
        $inconsistent = $this->receipts->problems($placed, $placed->anchor ?? $anchor, $record);

        if ($inconsistent !== []) {
            return new AnchorResolutionProblem(
                $index,
                $field->id,
                $field->recipientId,
                $anchor->text,
                ValidationCode::AnchorReceiptInconsistent,
                'a receipt this resolver could not have produced',
                'Field "'.$field->id.'" resolved to a receipt that contradicts the request it answers: '
                    .implode('; ', array_map(
                        static fn (array $problem): string => $problem['path'].' '.$problem['reason'],
                        $inconsistent,
                    )).'. This is a defect in resolution, not in the document.',
            );
        }

        return $placed;
    }

    /**
     * The count comes from the failure, which counted the matches on its way to being thrown.
     *
     * Scanning the runs again to learn it would double the cost of every failed field — and a
     * document where many fields fail is exactly the one where that matters, since a schema has
     * no field limit and the scan is over every run on the page.
     */
    private function failureProblem(
        int $index,
        FieldDefinition $field,
        AnchorPlacement $anchor,
        AnchorResolutionException $failure,
    ): AnchorResolutionProblem {
        $count = $failure->matchCount;

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

    /**
     * The resolved rectangle has to fit the bound its own receipt member carries.
     *
     * `anchor.resolved.rect` is 1.1's `$defs/resolved_rect` and is bounded at PDF's largest page
     * side; the field's own `rect` is 1.0's and is not (#105). So a legal offset on a legal
     * measurement can produce a rectangle this service would write and then refuse on the next
     * import — the one failure mode a caller can do nothing about, because the document they sent
     * was valid. It is checked here, where the offset that caused it can be named.
     *
     * Reachable when the page-fit check above did not already refuse it: with no page sizes to
     * check against, or on a page larger than the bound. Judged on canonical values, because the
     * receipt stores canonical values and the validator will read those.
     */
    private function outOfBoundsProblem(
        int $index,
        FieldDefinition $field,
        AnchorPlacement $anchor,
        ResolvedAnchor $found,
    ): ?AnchorResolutionProblem {
        $components = [
            'x' => $found->resolvedRect->x,
            'y' => $found->resolvedRect->y,
            'width' => $found->resolvedRect->width,
            'height' => $found->resolvedRect->height,
        ];

        foreach ($components as $name => $value) {
            if (abs(CanonicalNumber::round($value)) <= MeasuredRect::MAX_MAGNITUDE) {
                continue;
            }

            $where = $name.' '.$this->number($value);

            return new AnchorResolutionProblem(
                $index,
                $field->id,
                $field->recipientId,
                $anchor->text,
                ValidationCode::AnchorResolvedOffPage,
                $where,
                'Field "'.$field->id.'" is anchored to "'.$anchor->text.'" on page '.$field->page
                    .', and the offset puts the resolved rectangle beyond '
                    .$this->number(MeasuredRect::MAX_MAGNITUDE).' pt from the origin, PDF\'s largest page side ('
                    .$where.'). A rectangle that far out cannot be recorded in the receipt, so nothing is stored.',
            );
        }

        return null;
    }

    private function offPageProblem(
        int $index,
        FieldDefinition $field,
        AnchorPlacement $anchor,
        ResolvedAnchor $found,
        ?PageSizes $pageSizes,
    ): ?AnchorResolutionProblem {
        // Judged on the rectangle as it will be *stored*, not as it was computed. The receipt
        // canonicalises each component to three decimals, so a field whose raw right edge is
        // 612.0004 on a 612 pt page is exactly on the edge once written down — refusing it would
        // block a legitimately edge-aligned field for a difference the document does not contain.
        // The same rule as the cross-check comparison beside it, and as the field-schema
        // validator's page-fit check: compare what will be stored.
        $rect = new NativeRect(
            CanonicalNumber::round($found->resolvedRect->x),
            CanonicalNumber::round($found->resolvedRect->y),
            CanonicalNumber::round($found->resolvedRect->width),
            CanonicalNumber::round($found->resolvedRect->height),
        );

        $offPage = $rect->x < -CanonicalNumber::TOLERANCE || $rect->y < -CanonicalNumber::TOLERANCE;
        $size = null;

        if (! $offPage && $pageSizes instanceof PageSizes && $pageSizes->has($field->page)) {
            $size = $pageSizes->of($field->page);
            // The *derived* edges are rounded too, not only the components. Canonical 601.998 and
            // 10.003 sum to 612.00100000000009 while `612 + 0.001` is 612.00099999999998, so an
            // edge exactly on the permitted boundary would be refused for a difference that exists
            // only in the representation. The field-schema validator rounds its derived edge for
            // the same reason.
            $offPage = CanonicalNumber::round($rect->right()) > $size['width'] + CanonicalNumber::TOLERANCE
                || CanonicalNumber::round($rect->bottom()) > $size['height'] + CanonicalNumber::TOLERANCE;
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

        // Compared on the numbers the document will hold, and on a *rounded distance*. Both
        // matter, and they are different mistakes. Comparing raw coordinates judges a value the
        // document never carries — extraction produces sub-thousandth positions that
        // canonicalisation removes — so a receipt could be refused for a disagreement that does
        // not exist once stored, with the message reporting it as "off by 0". And subtracting two
        // already-canonical values can land a few ulps above a bound they sit exactly on, which
        // refuses a cross-check that is precisely at its stated tolerance.
        //
        // This is the rule the field-schema validator arrived at over several review rounds
        // (`checkCrossCheckReceiptAgrees`), and it is the same rule here: compare what will be
        // stored, and round the comparison itself.
        $tolerance = CanonicalNumber::round($this->toleranceFor($anchor));
        $dx = self::canonicalDistance($found->resolvedRect->x, $field->rect->x);
        $dy = self::canonicalDistance($found->resolvedRect->y, $field->rect->y);

        if ($dx <= $tolerance && $dy <= $tolerance) {
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

    /** The gap between two coordinates, as the stored document would measure it. */
    private static function canonicalDistance(float $a, float $b): float
    {
        return CanonicalNumber::round(abs(CanonicalNumber::round($a) - CanonicalNumber::round($b)));
    }

    /**
     * The tolerance a cross-check is judged against: the document's, else the deployment's.
     *
     * Canonical, because it is compared against canonical distances and, when the deployment's is
     * used, recorded in the receipt as the number the check was actually made with.
     */
    private function toleranceFor(AnchorPlacement $anchor): float
    {
        return CanonicalNumber::round($anchor->tolerance ?? $this->defaultCrossCheckTolerance);
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
