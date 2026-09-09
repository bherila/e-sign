<?php

declare(strict_types=1);

namespace Tests\Unit\Preparation\Anchoring;

use App\Domain\Preparation\Anchoring\AnchorResolutionFailed;
use App\Domain\Preparation\Anchoring\SchemaAnchorResolver;
use App\Domain\Preparation\Geometry\NativeRect;
use App\Domain\Preparation\Schema\AnchorPlacement;
use App\Domain\Preparation\Schema\AnchorPlacementMode;
use App\Domain\Preparation\Schema\CanonicalNumber;
use App\Domain\Preparation\Schema\FieldSchemaDocument;
use App\Domain\Preparation\Schema\PageSizes;
use App\Domain\Preparation\Schema\SchemaVersion;
use App\Domain\Preparation\Schema\ValidationCode;
use App\Domain\Preparation\TcPdf\TcPdfTextLocator;
use App\Domain\Preparation\Text\Anchor;
use App\Domain\Preparation\Text\AnchorOccurrence;
use App\Domain\Preparation\Text\AnchorOrigin;
use App\Domain\Preparation\Text\AnchorResolver;
use App\Domain\Preparation\Text\TextRun;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\PdfFixtures;

/**
 * What an anchor in a *document* resolves to, over the Stage 0 fixture matrix (issue #23).
 *
 * `AnchorResolverTest` covers the semantics — which match wins, what absent and ambiguous mean.
 * This covers what those semantics do to a field: which rectangle is stored, which failures are
 * refusals, what happens to a field whose optional anchor is genuinely not there, and the
 * property everything downstream rests on — that the rectangle written into a field is exactly
 * the one `AnchorResolver` returns for the same input, on every page geometry, with no second
 * measurement anywhere in between.
 */
final class SchemaAnchorResolverTest extends TestCase
{
    private const DIGEST = 'aa11bb22cc33dd44ee55ff66007788990011223344556677889900aabbccddee';

    private const OTHER_DIGEST = 'ff00ee11dd22cc33bb44aa5566778899001122334455667788990011223344ff';

    // ------------------------------------------------------------------ the stored rectangle

    /**
     * The stored rectangle is the resolver's answer, not a re-derivation of it.
     *
     * Every geometry in the matrix: a plain page, a page rotated a quarter turn, a page whose
     * CropBox does not start at the origin, and a page where the string occurs several times.
     * If anything in the field-document path measured the page a second time, one of these would
     * disagree — a rotated page and an offset CropBox are exactly where a second measurement
     * goes wrong, and where it would go wrong quietly.
     *
     * @return array<string, array{string, int, int, string|int}>
     */
    public static function geometries(): array
    {
        return [
            'plain letter page' => ['single-page-letter', 1, 792, 'sole'],
            'page rotated 90 degrees' => ['rotated-pages', 1, 612, 'sole'],
            'page rotated 180 degrees' => ['rotated-pages', 2, 792, 'sole'],
            'page rotated 270 degrees' => ['rotated-pages', 3, 612, 'sole'],
            'offset crop box' => ['cropbox-offset', 1, 696, 'sole'],
            'offset crop box, rotated' => ['cropbox-offset', 2, 540, 'sole'],
            'the second of three occurrences' => ['multi-occurrence', 1, 792, 2],
        ];
    }

    #[DataProvider('geometries')]
    public function test_the_stored_rectangle_is_exactly_what_the_anchor_resolver_returns(
        string $fixture,
        int $page,
        int $nativeHeight,
        string|int $occurrence,
    ): void {
        $runs = (new TcPdfTextLocator)->extract(PdfFixtures::bytes($fixture));

        $document = $this->documentWith([
            'text' => 'Signature:',
            'placement' => AnchorPlacementMode::Replace->value,
            'occurrence' => $occurrence,
            'origin' => AnchorOrigin::BottomLeft->value,
            'offset' => ['dx' => 4, 'dy' => 6],
        ], page: $page);

        $expected = (new AnchorResolver)->resolve($runs, new Anchor(
            text: 'Signature:',
            occurrence: $occurrence === 'sole' ? AnchorOccurrence::sole() : AnchorOccurrence::index((int) $occurrence),
            page: $page,
            offsetX: 4.0,
            offsetY: 6.0,
            width: 170.0,
            height: 36.0,
            origin: AnchorOrigin::BottomLeft,
        ))[0];

        $outcome = $this->resolver()->resolve($document, $runs, self::DIGEST);
        $field = $outcome->schema->field('signature');

        $this->assertNotNull($field);
        $this->assertSame(['signature'], $outcome->resolved);
        $this->assertEqualsWithDelta($expected->resolvedRect->x, $field->rect->x, 0.001);
        $this->assertEqualsWithDelta($expected->resolvedRect->y, $field->rect->y, 0.001);
        $this->assertSame(170.0, $field->rect->width);
        $this->assertSame(36.0, $field->rect->height);

        // And the receipt records where the *text* was, which is a different rectangle.
        $receipt = $field->anchor?->resolved;
        $this->assertNotNull($receipt);
        $this->assertSame(self::DIGEST, $receipt->documentSha256);
        $this->assertSame($page, $receipt->page);
        $this->assertSame($occurrence === 'sole' ? 1 : (int) $occurrence, $receipt->occurrenceIndex);
        $this->assertEqualsWithDelta($expected->anchorRect->x, $receipt->anchorRect->x, 0.001);
        $this->assertEqualsWithDelta($expected->anchorRect->y, $receipt->anchorRect->y, 0.001);

        // The stored rectangle is on the page it claims to be on.
        $this->assertLessThanOrEqual((float) $nativeHeight, $field->rect->bottom());
    }

    public function test_a_resolved_field_is_indistinguishable_from_a_hand_placed_one(): void
    {
        $runs = (new TcPdfTextLocator)->extract(PdfFixtures::bytes('single-page-letter'));
        $resolved = $this->resolver()
            ->resolve($this->documentWith($this->replacingAnchor()), $runs, self::DIGEST)
            ->schema
            ->field('signature');

        $this->assertNotNull($resolved);

        // Same id, recipient, type, page and rectangle as a field somebody dragged there. The
        // anchor beside it is provenance; nothing downstream reads it.
        $handPlaced = $this->documentWith(null, rect: $resolved->rect->toArray())->field('signature');

        $this->assertNotNull($handPlaced);
        $this->assertSame($handPlaced->rect->toArray(), $resolved->rect->toArray());
        $this->assertSame(
            array_diff_key($handPlaced->toArray(), ['anchor' => null]),
            array_diff_key($resolved->toArray(), ['anchor' => null]),
        );
    }

    // ------------------------------------------------------------------ a receipt is not a licence

    /**
     * The same request against the same bytes twice: the same answer, and it was looked up twice.
     *
     * Resolution is a pure function of the request and the document, so a second pass over an
     * unchanged pair costs a parse and changes nothing. That is what makes it safe to run every
     * time rather than only where a receipt happens to be missing.
     */
    public function test_resolving_the_same_request_against_the_same_bytes_twice_is_idempotent(): void
    {
        $runs = (new TcPdfTextLocator)->extract(PdfFixtures::bytes('single-page-letter'));
        $once = $this->resolver()->resolve($this->documentWith($this->replacingAnchor()), $runs, self::DIGEST);

        $twice = $this->resolver()->resolve($once->schema, $runs, self::DIGEST);

        $this->assertSame(['signature'], $twice->resolved);
        $this->assertSame($once->schema->canonicalJson(), $twice->schema->canonicalJson());
    }

    /**
     * A receipt records what was found; it never stands in for looking again.
     *
     * The receipt binds its rectangle to a document, a page and an occurrence — not to the anchor
     * *text*, the origin corner or the offset. Honouring one would let an edited request keep the
     * answer to the question it used to ask: the text below is no longer anywhere in the
     * document, and a required anchor that cannot be found has to stop the document rather than
     * publish and send at the coordinates the old text resolved to.
     */
    public function test_a_receipt_does_not_answer_an_anchor_whose_text_has_since_changed(): void
    {
        $runs = (new TcPdfTextLocator)->extract(PdfFixtures::bytes('single-page-letter'));
        $once = $this->resolver()->resolve($this->documentWith($this->replacingAnchor()), $runs, self::DIGEST);

        $edited = $this->withAnchorText($once->schema, 'Nowhere in this document:');

        $failure = $this->failureOf($edited, $runs);

        $this->assertCount(1, $failure->problems);
        $this->assertSame(ValidationCode::AnchorNotFound, $failure->problems[0]->code);
        $this->assertSame('Nowhere in this document:', $failure->problems[0]->anchorText);
    }

    /** And when the edited text *is* in the document, the field moves to it rather than staying put. */
    public function test_an_edited_anchor_text_is_resolved_to_its_own_match(): void
    {
        $runs = (new TcPdfTextLocator)->extract(PdfFixtures::bytes('single-page-letter'));
        $once = $this->resolver()->resolve($this->documentWith($this->replacingAnchor()), $runs, self::DIGEST);
        $wasAt = $once->schema->field('signature')?->rect->toArray();

        $again = $this->resolver()->resolve(
            $this->withAnchorText($once->schema, 'Printed Name:'),
            $runs,
            self::DIGEST,
        );

        $moved = $again->schema->field('signature');

        $this->assertSame(['signature'], $again->resolved);
        $this->assertNotNull($moved);
        $this->assertNotSame($wasAt, $moved->rect->toArray());
        $this->assertSame($moved->rect->toArray(), $moved->anchor?->resolved?->rect->toArray());
    }

    public function test_a_receipt_from_a_different_revision_is_resolved_again(): void
    {
        $runs = (new TcPdfTextLocator)->extract(PdfFixtures::bytes('single-page-letter'));
        $once = $this->resolver()->resolve($this->documentWith($this->replacingAnchor()), $runs, self::DIGEST);

        $again = $this->resolver()->resolve($once->schema, $runs, self::OTHER_DIGEST);

        // A rectangle measured in one document is not evidence about another one.
        $this->assertSame(['signature'], $again->resolved);
        $this->assertSame(self::OTHER_DIGEST, $again->schema->field('signature')?->anchor?->resolved?->documentSha256);
    }

    public function test_a_document_with_no_anchors_is_returned_untouched(): void
    {
        $document = $this->documentWith(null);

        $outcome = $this->resolver()->resolve($document, [], self::DIGEST);

        $this->assertFalse($outcome->changed());
        $this->assertSame($document->canonicalJson(), $outcome->schema->canonicalJson());
    }

    // ------------------------------------------------------------------ visible failure

    public function test_an_absent_required_anchor_names_the_field_and_the_text(): void
    {
        $runs = (new TcPdfTextLocator)->extract(PdfFixtures::bytes('single-page-letter'));
        $document = $this->documentWith($this->replacingAnchor(['text' => 'Witness signature:']));

        $failure = $this->failureOf($document, $runs);

        $this->assertCount(1, $failure->problems);
        $problem = $failure->problems[0];
        $this->assertSame(ValidationCode::AnchorNotFound, $problem->code);
        $this->assertSame('signature', $problem->fieldId);
        $this->assertSame('Witness signature:', $problem->anchorText);
        $this->assertSame('no match on page 1', $problem->found);
        $this->assertSame('/fields/0/anchor', $problem->pointer());
        $this->assertStringContainsString('"signature"', $problem->message);
        $this->assertStringContainsString('Witness signature:', $problem->message);
    }

    public function test_an_ambiguous_anchor_is_refused_and_says_how_many_it_found(): void
    {
        $runs = (new TcPdfTextLocator)->extract(PdfFixtures::bytes('multi-occurrence'));
        $document = $this->documentWith($this->replacingAnchor(['occurrence' => 'sole']));

        $problem = $this->failureOf($document, $runs)->problems[0];

        $this->assertSame(ValidationCode::AnchorAmbiguous, $problem->code);
        $this->assertSame('3 matches on page 1', $problem->found);
        $this->assertStringContainsString('matched 3 times', $problem->message);
    }

    public function test_an_occurrence_beyond_the_matches_is_refused(): void
    {
        $runs = (new TcPdfTextLocator)->extract(PdfFixtures::bytes('multi-occurrence'));
        $document = $this->documentWith($this->replacingAnchor(['occurrence' => 9]));

        $problem = $this->failureOf($document, $runs)->problems[0];

        $this->assertSame(ValidationCode::AnchorOccurrenceOutOfRange, $problem->code);
        $this->assertSame('3 matches on page 1', $problem->found);
    }

    /**
     * Ambiguity is never excused by the compatibility option.
     *
     * `required: false` says the text may be absent. It says nothing about what to do when the
     * text is there several times, and a field that could go in three places is not a field
     * anybody can be asked to sign.
     */
    public function test_an_optional_anchor_is_still_refused_when_it_is_ambiguous(): void
    {
        $runs = (new TcPdfTextLocator)->extract(PdfFixtures::bytes('multi-occurrence'));
        $document = $this->documentWith(
            $this->replacingAnchor(['occurrence' => 'sole', 'required' => false]),
            required: false,
        );

        $this->assertSame(ValidationCode::AnchorAmbiguous, $this->failureOf($document, $runs)->problems[0]->code);
    }

    public function test_an_offset_that_leaves_the_page_is_refused_rather_than_stored(): void
    {
        $runs = (new TcPdfTextLocator)->extract(PdfFixtures::bytes('single-page-letter'));
        $document = $this->documentWith($this->replacingAnchor(['offset' => ['dx' => 500, 'dy' => 0]]));

        $problem = $this->failureOf($document, $runs, PageSizes::uniform(1, 612.0, 792.0))->problems[0];

        $this->assertSame(ValidationCode::AnchorResolvedOffPage, $problem->code);
        $this->assertStringContainsString('off the page', $problem->message);
    }

    public function test_every_unplaceable_field_is_reported_in_one_pass(): void
    {
        $runs = (new TcPdfTextLocator)->extract(PdfFixtures::bytes('multi-occurrence'));

        $document = FieldSchemaDocument::fromArray($this->documentArray([
            $this->fieldArray('first', $this->replacingAnchor(['text' => 'Absent:'])),
            $this->fieldArray('second', $this->replacingAnchor(['occurrence' => 'sole'])),
        ]));

        $failure = $this->failureOf($document, $runs);

        $this->assertSame(['first', 'second'], $failure->fieldIds());
        $this->assertSame(['/fields/0/anchor', '/fields/1/anchor'], array_map(
            static fn ($problem): string => $problem->pointer(),
            $failure->problems,
        ));
    }

    // ------------------------------------------------------------------ optional absence

    public function test_an_absent_optional_anchor_omits_the_field_and_records_it(): void
    {
        $runs = (new TcPdfTextLocator)->extract(PdfFixtures::bytes('single-page-letter'));
        $document = $this->documentWith(
            $this->replacingAnchor(['text' => 'Witness signature:', 'required' => false]),
            required: false,
        );

        $outcome = $this->resolver()->resolve($document, $runs, self::DIGEST);

        $this->assertTrue($outcome->changed());
        $this->assertNull($outcome->schema->field('signature'), 'The field is not placed anywhere.');
        $this->assertCount(1, $outcome->omissions);
        $this->assertSame([
            'field_id' => 'signature',
            'recipient_id' => 'signer',
            'type' => 'signature',
            'alias' => null,
            'page' => 1,
            'anchor_text' => 'Witness signature:',
            'occurrence' => 'sole',
            'reason' => 'optional_anchor_absent',
        ], $outcome->omissionsToArray()[0]);
    }

    public function test_an_absent_optional_anchor_can_be_reported_without_removing_the_field(): void
    {
        $runs = (new TcPdfTextLocator)->extract(PdfFixtures::bytes('single-page-letter'));
        $document = $this->documentWith(
            $this->replacingAnchor(['text' => 'Witness signature:', 'required' => false]),
            required: false,
        );

        // What publishing a template version does: report it, change nothing. Which fields an
        // envelope leaves out is a fact about that envelope.
        $outcome = $this->resolver()->resolve($document, $runs, self::DIGEST, null, omitAbsentFields: false);

        $this->assertFalse($outcome->changed());
        $this->assertNotNull($outcome->schema->field('signature'));
        $this->assertCount(1, $outcome->omissions);
    }

    // ------------------------------------------------------------------ cross-check

    public function test_a_cross_checked_anchor_keeps_the_declared_rectangle(): void
    {
        $runs = (new TcPdfTextLocator)->extract(PdfFixtures::bytes('single-page-letter'));

        // "Signature:" sits at native (72, 582.4); with a top-left origin and no offset the
        // anchor resolves onto exactly that corner, and the declared rectangle says the same.
        $document = $this->documentWith([
            'text' => 'Signature:',
            'occurrence' => 'sole',
            'placement' => AnchorPlacementMode::CrossCheck->value,
        ], rect: ['x' => 72, 'y' => 582.4, 'width' => 170, 'height' => 36]);

        $field = $this->resolver()->resolve($document, $runs, self::DIGEST)->schema->field('signature');

        $this->assertNotNull($field);
        $this->assertSame(['x' => 72, 'y' => 582.4, 'width' => 170, 'height' => 36], $field->rect->toArray());
        $this->assertNotNull($field->anchor?->resolved);
    }

    public function test_a_cross_check_that_disagrees_beyond_the_tolerance_fails(): void
    {
        $runs = (new TcPdfTextLocator)->extract(PdfFixtures::bytes('single-page-letter'));

        $document = $this->documentWith([
            'text' => 'Signature:',
            'occurrence' => 'sole',
            'placement' => AnchorPlacementMode::CrossCheck->value,
            'tolerance' => 2,
        ], rect: ['x' => 72, 'y' => 600, 'width' => 170, 'height' => 36]);

        $problem = $this->failureOf($document, $runs)->problems[0];

        $this->assertSame(ValidationCode::AnchorCrossCheckFailed, $problem->code);
        $this->assertStringContainsString('off by 0, 17.6 pt', $problem->found);
        $this->assertStringContainsString('One of them is stale', $problem->message);
    }

    public function test_the_tolerance_is_configurable_and_a_wider_one_accepts_the_same_document(): void
    {
        $runs = (new TcPdfTextLocator)->extract(PdfFixtures::bytes('single-page-letter'));

        $document = $this->documentWith([
            'text' => 'Signature:',
            'occurrence' => 'sole',
            'placement' => AnchorPlacementMode::CrossCheck->value,
            'tolerance' => 20,
        ], rect: ['x' => 72, 'y' => 600, 'width' => 170, 'height' => 36]);

        $this->assertSame(['signature'], $this->resolver()->resolve($document, $runs, self::DIGEST)->resolved);
    }

    public function test_the_deployment_default_applies_when_a_document_names_no_tolerance(): void
    {
        $runs = (new TcPdfTextLocator)->extract(PdfFixtures::bytes('single-page-letter'));

        $document = $this->documentWith([
            'text' => 'Signature:',
            'occurrence' => 'sole',
            'placement' => AnchorPlacementMode::CrossCheck->value,
        ], rect: ['x' => 72, 'y' => 600, 'width' => 170, 'height' => 36]);

        $generous = new SchemaAnchorResolver(new AnchorResolver, 50.0);

        $this->assertSame(['signature'], $generous->resolve($document, $runs, self::DIGEST)->resolved);
        $this->assertSame(ValidationCode::AnchorCrossCheckFailed, $this->failureOf($document, $runs)->problems[0]->code);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * A cross-check is judged at the bound it states, on the numbers the document will hold.
     *
     * Two mistakes are possible and they are different. The resolved side comes from extraction
     * and carries whatever precision the font metrics produced, so comparing it raw judges a value
     * the document never holds. And subtracting two already-canonical values can land a few ulps
     * above the bound they sit exactly on, which refuses a cross-check that is precisely at its
     * stated tolerance.
     *
     * The *declared* side needs no such care and cannot be given any: from 1.1 the schema refuses
     * a coordinate finer than three decimals, so a declared rectangle is canonical by the time it
     * reaches here. Rounding it is defensive; rounding the resolved side and the distance is not.
     *
     * The combination is distance against boundary, which is why one case cannot stand in for the
     * others: a test just inside the bound passes while exact matches are refused, and a test at
     * zero passes while everything near the boundary is wrong. The offsets are taken from the
     * resolver's own answer rather than from a number written down here, so the case says what it
     * means even if the fixture moves.
     *
     * @return iterable<string, array{float, float, bool}>
     */
    public static function crossCheckBoundaries(): iterable
    {
        //                                  offset from the resolved corner, tolerance, accepted?
        yield 'an exact match' => [0.0, 1.0, true];
        yield 'inside the tolerance' => [0.5, 1.0, true];
        yield 'exactly at the tolerance' => [1.0, 1.0, true];
        yield 'one canonical step past it' => [1.001, 1.0, false];
        yield 'well past it' => [8.0, 1.0, false];
        yield 'an exact match at zero tolerance' => [0.0, 0.0, true];
        yield 'one canonical step away at zero tolerance' => [0.001, 0.0, false];
    }

    /**
     * The resolved side carries whatever precision the page's metrics produced.
     *
     * The declared side is always canonical — from 1.1 the schema refuses a finer coordinate — so
     * the value that can be sub-thousandth is the one extraction hands back. A run whose glyphs
     * divide unevenly gives a match rectangle a fraction of a thousandth from the round number the
     * document declares, and comparing raw refuses that as a disagreement while the stored
     * document shows none: the message even reports it as "off by 0", because the numbers it
     * prints are canonical and the numbers it compared were not.
     *
     * Synthetic runs rather than a fixture, because the fixture matrix resolves to exact values
     * and cannot exercise this. A test that cannot fail is the thing this branch keeps finding.
     */
    public function test_a_sub_thousandth_resolved_position_is_not_a_disagreement(): void
    {
        // A run whose width does not divide evenly by its character count, so the match rectangle
        // lands a fraction of a thousandth away from a round number.
        $runs = [new TextRun(
            page: 1,
            text: 'xxSignature:',
            rect: new NativeRect(60.0, 200.0, 72.0004 * 12 / 10, 12.0),
            fontSize: 12.0,
            fontResource: 'F1',
        )];

        $document = $this->documentWith(
            $this->replacingAnchor([
                'placement' => AnchorPlacementMode::CrossCheck->value,
                'tolerance' => 0,
            ]),
            rect: ['x' => 74.4, 'y' => 200, 'width' => 170, 'height' => 36],
        );

        $resolved = (new AnchorResolver)->resolve(
            $runs,
            $document->fields[0]->anchor?->toAnchor(1, 170, 36) ?? throw new InvalidArgumentException('no anchor'),
        )[0];

        // Precondition: the resolver really did produce a sub-thousandth coordinate, and the
        // declared rectangle is the canonical form of it. Without this the case proves nothing.
        $this->assertNotSame(CanonicalNumber::round($resolved->resolvedRect->x), $resolved->resolvedRect->x);
        $this->assertSame(74.4, CanonicalNumber::round($resolved->resolvedRect->x));

        $this->assertSame(['signature'], $this->resolver()->resolve($document, $runs, self::DIGEST)->resolved);
    }

    #[DataProvider('crossCheckBoundaries')]
    public function test_a_cross_check_is_judged_on_stored_values_at_its_stated_bound(
        float $offset,
        float $tolerance,
        bool $accepted,
    ): void {
        $runs = (new TcPdfTextLocator)->extract(PdfFixtures::bytes('single-page-letter'));

        // Where this anchor actually resolves, asked rather than assumed.
        $placed = $this->resolver()
            ->resolve($this->documentWith($this->replacingAnchor()), $runs, self::DIGEST)
            ->schema->field('signature')?->rect;

        $this->assertNotNull($placed);

        $document = $this->documentWith(
            $this->replacingAnchor([
                'placement' => AnchorPlacementMode::CrossCheck->value,
                'tolerance' => $tolerance,
            ]),
            rect: [
                'x' => CanonicalNumber::round($placed->x + $offset),
                'y' => $placed->y,
                'width' => $placed->width,
                'height' => $placed->height,
            ],
        );

        if ($accepted) {
            $this->assertSame(['signature'], $this->resolver()->resolve($document, $runs, self::DIGEST)->resolved);

            return;
        }

        $this->assertSame(
            ValidationCode::AnchorCrossCheckFailed,
            $this->failureOf($document, $runs)->problems[0]->code,
        );
    }

    /**
     * A deployment's tolerance is held to the range a document's is, and refused where it is set.
     *
     * Unvalidated, a negative setting reports every exact match as `anchor_cross_check_failed` — a
     * 422 blaming a document that is right — and one above the maximum reaches
     * `AnchorPlacement::withTolerance()` on a *successful* match, throwing at request time. Both
     * are configuration mistakes wearing a document's clothes.
     *
     * Sign and magnitude are independent, so both ends are checked along with the two values that
     * must remain legal.
     *
     * @return iterable<string, array{float, bool}>
     */
    public static function configuredTolerances(): iterable
    {
        yield 'zero' => [0.0, true];
        yield 'the default' => [SchemaAnchorResolver::DEFAULT_CROSS_CHECK_TOLERANCE, true];
        yield 'the largest page side' => [AnchorPlacement::MAX_TOLERANCE, true];
        yield 'negative' => [-1.0, false];
        yield 'one step past the largest page side' => [AnchorPlacement::MAX_TOLERANCE + 0.001, false];
        yield 'not a number' => [NAN, false];
    }

    #[DataProvider('configuredTolerances')]
    public function test_the_deployment_tolerance_is_refused_where_it_is_configured(float $tolerance, bool $legal): void
    {
        if (! $legal) {
            $this->expectException(InvalidArgumentException::class);
        }

        $resolver = new SchemaAnchorResolver(new AnchorResolver, $tolerance);

        $this->assertInstanceOf(SchemaAnchorResolver::class, $resolver);
    }

    private function resolver(): SchemaAnchorResolver
    {
        return new SchemaAnchorResolver(new AnchorResolver);
    }

    /**
     * @param  array<int, TextRun>  $runs
     */
    private function failureOf(FieldSchemaDocument $document, array $runs, ?PageSizes $pages = null): AnchorResolutionFailed
    {
        try {
            $this->resolver()->resolve($document, $runs, self::DIGEST, $pages);
        } catch (AnchorResolutionFailed $failed) {
            return $failed;
        }

        $this->fail('Expected the anchors to be refused.');
    }

    /** The same document with one field's anchor text edited and its receipt left in place. */
    private function withAnchorText(FieldSchemaDocument $schema, string $text): FieldSchemaDocument
    {
        $document = $schema->toArray();
        $document['fields'][0]['anchor']['text'] = $text;

        return FieldSchemaDocument::fromArray($document);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function replacingAnchor(array $overrides = []): array
    {
        return array_replace([
            'text' => 'Signature:',
            'occurrence' => 'sole',
            'placement' => AnchorPlacementMode::Replace->value,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>|null  $anchor
     * @param  array{x: int|float, y: int|float, width: int|float, height: int|float}|null  $rect
     */
    private function documentWith(?array $anchor, int $page = 1, ?array $rect = null, bool $required = true): FieldSchemaDocument
    {
        return FieldSchemaDocument::fromArray($this->documentArray([
            $this->fieldArray('signature', $anchor, $page, $rect, $required),
        ]));
    }

    /**
     * @param  array<string, mixed>|null  $anchor
     * @param  array{x: int|float, y: int|float, width: int|float, height: int|float}|null  $rect
     * @return array<string, mixed>
     */
    private function fieldArray(
        string $id,
        ?array $anchor,
        int $page = 1,
        ?array $rect = null,
        bool $required = true,
    ): array {
        $field = [
            'id' => $id,
            'recipient_id' => 'signer',
            'type' => 'signature',
            'page' => $page,
            // In `replace` mode x and y are the declared placeholder resolution overwrites; the
            // width and height are the field's size, which no anchor supplies.
            'rect' => $rect ?? ['x' => 1, 'y' => 1, 'width' => 170, 'height' => 36],
            'required' => $required,
            'read_only' => false,
        ];

        if ($anchor !== null) {
            $field['anchor'] = $anchor;
        }

        return $field;
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    private function documentArray(array $fields): array
    {
        return [
            // These documents use anchor members that arrived in 1.1.
            'schema_version' => SchemaVersion::CURRENT,
            'document_id' => 'doc_anchor_unit',
            'coordinate_space' => [
                'unit' => 'pt',
                'origin' => 'top-left',
                'page_box' => 'crop',
                'rotation' => 'displayed',
                'page_index_base' => 1,
            ],
            'recipients' => [
                ['id' => 'signer', 'name' => 'Example Signer', 'email' => 'signer@example.test'],
            ],
            'signing_order' => [['signer']],
            'fields' => $fields,
        ];
    }
}
