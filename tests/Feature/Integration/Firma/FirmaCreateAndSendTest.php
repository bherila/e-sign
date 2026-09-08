<?php

declare(strict_types=1);

namespace Tests\Feature\Integration\Firma;

use App\Domain\Identity\Credentials\IssuedServiceCredential;
use App\Domain\Preparation\Documents\DocumentBlobStore;
use App\Domain\Preparation\Documents\Models\Document;
use App\Domain\Preparation\Schema\AnchorPlacementMode;
use App\Domain\Preparation\Schema\FieldSchemaValidator;
use App\Domain\Preparation\Schema\SchemaVersion;
use App\Domain\Signing\Envelopes\EnvelopeState;
use App\Domain\Signing\Models\Envelope;
use App\Domain\Signing\Sessions\Models\RecipientInvitation;
use App\Domain\Signing\Sessions\OtpRequirement;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\Support\FirmaFacadeScenario;
use Tests\Support\PdfFixtures;
use Tests\TestCase;

/**
 * `POST /signing-requests/create-and-send`: a base64 PDF, percent coordinates, one call.
 *
 * The load-bearing assertion in this file is the coordinate one. The profile's `position` is
 * a percentage of the page and the native field schema is in points, and the whole of
 * `AGENTS.md`'s "coordinates are never guessed" rule comes down to whether the conversion is
 * the declared one. So the tests below place a field at a percentage and assert the **exact
 * native rectangle** it lands on, computed from the fixture's own page size — not "roughly
 * right", and not compared against whatever the code happened to produce.
 *
 * `single-page-letter.pdf` is 612 × 792 pt, so 10% of `x` is 61.2 pt and 20% of `y` is
 * 158.4 pt. A build that read the numbers as points would place the same field at 10 pt and
 * 20 pt, which is a different place on the page and would pass a tolerance-based test.
 */
class FirmaCreateAndSendTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/functions/v1/signing-request-api/signing-requests';

    /** The fixture's page geometry, from tests/Fixtures/pdf/manifest.json. */
    private const PAGE_WIDTH = 612.0;

    private const PAGE_HEIGHT = 792.0;

    public function test_percent_coordinates_land_on_the_declared_native_rectangle(): void
    {
        [, $issued] = $this->scenario();

        $response = $this->postJson(self::BASE.'/create-and-send', [
            'name' => 'Synthetic percent-coordinate agreement',
            'document' => base64_encode(PdfFixtures::bytes('single-page-letter')),
            'recipients' => [
                ['first_name' => 'Dana', 'last_name' => 'Buyer', 'email' => 'dana@buyer.example.test', 'designation' => 'Signer', 'order' => 1],
            ],
            'fields' => [
                [
                    'type' => 'signature',
                    'page_number' => 1,
                    'variable_name' => 'Buyer Signature',
                    'position' => ['x' => 10.0, 'y' => 20.0, 'width' => 30.0, 'height' => 5.0],
                ],
            ],
        ], FirmaFacadeScenario::headers($issued))->assertStatus(201);

        $envelope = Envelope::query()->where('public_id', $response->json('id'))->firstOrFail();
        $field = $envelope->fieldSchema()->fields[0];

        // The declared conversion: percent of the displayed page → points, top-left origin.
        $this->assertSame(round(10.0 / 100 * self::PAGE_WIDTH, 3), $field->rect->x);
        $this->assertSame(round(20.0 / 100 * self::PAGE_HEIGHT, 3), $field->rect->y);
        $this->assertSame(round(30.0 / 100 * self::PAGE_WIDTH, 3), $field->rect->width);
        $this->assertSame(round(5.0 / 100 * self::PAGE_HEIGHT, 3), $field->rect->height);

        // A points reading of the same numbers would have put it at 10, 20 — a different
        // place on the page, and the failure mode "never infer the unit from the magnitude"
        // exists to prevent.
        $this->assertNotSame(10.0, $field->rect->x);

        // And back out again: `/fields` returns the percentages the caller sent.
        $row = $this->getJson(
            self::BASE.'/'.$envelope->public_id.'/fields',
            FirmaFacadeScenario::headers($issued),
        )->assertOk()->json('results.0');

        $this->assertEqualsWithDelta(10.0, $row['x_postion'], 0.001);
        $this->assertEqualsWithDelta(20.0, $row['y_position'], 0.001);
        $this->assertEqualsWithDelta(30.0, $row['width'], 0.001);
        $this->assertEqualsWithDelta(5.0, $row['heigh'], 0.001);

        // The caller's variable name survives verbatim, spaces and all, even though the
        // native schema's `alias` is an identifier that cannot hold it.
        $this->assertSame('Buyer Signature', $row['variable_name']);
        $this->assertSame('buyer_signature', $field->alias);

    }

    /**
     * A variable name that is not an identifier survives, and a PATCH resolves it.
     *
     * This is the consumer's real case: the recorded fixtures carry `Company/Individual Name`
     * and `Signing Date`, spaces and slash included, and the native field schema's `alias` is
     * an identifier that cannot hold either. So the caller's string is kept verbatim in the
     * schema's `label` and a normalised copy goes in `alias` — and both spellings have to
     * resolve, or a consumer patching prefills by name breaks on its own field names.
     */
    public function test_a_variable_name_with_spaces_round_trips_and_resolves_on_patch(): void
    {
        [, $issued] = $this->scenario();

        $created = $this->postJson(self::BASE, [
            'name' => 'Synthetic agreement with awkward variable names',
            'document' => base64_encode(PdfFixtures::bytes('single-page-letter')),
            'recipients' => [['first_name' => 'Dana', 'email' => 'dana@buyer.example.test', 'order' => 1]],
            'fields' => [
                [
                    'type' => 'text',
                    'page_number' => 1,
                    'variable_name' => 'Company/Individual Name',
                    'position' => ['x' => 10.0, 'y' => 20.0, 'width' => 40.0, 'height' => 3.0],
                ],
                [
                    'type' => 'signature',
                    'page_number' => 1,
                    'position' => ['x' => 10.0, 'y' => 80.0, 'width' => 25.0, 'height' => 4.0],
                ],
            ],
        ], FirmaFacadeScenario::headers($issued))->assertStatus(201);

        $id = $created->json('id');

        // Verbatim on the way back out, not the normalised copy.
        $this->assertSame('Company/Individual Name', $created->json('fields.0.variable_name'));

        // The consumer's own spelling resolves on PATCH.
        $this->patchJson(self::BASE.'/'.$id, [
            'field' => ['variable_name' => 'Company/Individual Name', 'value' => 'Example Holdings Ltd'],
        ], FirmaFacadeScenario::headers($issued))
            ->assertOk()
            ->assertJsonPath('variable_name', 'Company/Individual Name')
            ->assertJsonPath('value', 'Example Holdings Ltd');

        // And so does the normalised one, so a client that stored the schema alias is not
        // locked out of its own field.
        $this->patchJson(self::BASE.'/'.$id, [
            'field' => ['variable_name' => 'company_individual_name', 'value' => 'Example Holdings II Ltd'],
        ], FirmaFacadeScenario::headers($issued))
            ->assertOk()
            ->assertJsonPath('value', 'Example Holdings II Ltd');

        $this->assertSame(
            'Example Holdings II Ltd',
            $this->getJson(self::BASE.'/'.$id.'/fields', FirmaFacadeScenario::headers($issued))
                ->assertOk()->json('results.0.final_value'),
        );
    }

    /**
     * A percentage outside 0..100 is refused, never reinterpreted as points.
     *
     * This is disagreement D4 in one assertion: the upstream schema says percent, the
     * upstream examples in the same document use `{"x": 100, "y": 500}` and read as points.
     * The schema wins and the example is treated as an upstream error.
     */
    public function test_a_coordinate_outside_the_percent_range_is_refused(): void
    {
        [, $issued] = $this->scenario();

        $this->postJson(self::BASE.'/create-and-send', [
            'name' => 'Synthetic with the upstream example coordinates',
            'document' => base64_encode(PdfFixtures::bytes('single-page-letter')),
            'recipients' => [['first_name' => 'Dana', 'email' => 'dana@buyer.example.test', 'order' => 1]],
            'fields' => [[
                'type' => 'signature',
                'page_number' => 1,
                // Straight from the upstream document's own example.
                'position' => ['x' => 100, 'y' => 500, 'width' => 200, 'height' => 50],
            ]],
        ], FirmaFacadeScenario::headers($issued))
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_request')
            ->assertJsonPath('details.property', 'position.y');

        // Refused before the bytes were stored: a coordinate needs no page size to be
        // out of range, so this request leaves nothing behind at all.
        $this->assertSame(0, Envelope::query()->count());
        $this->assertSame(0, $this->ingestedCount());
    }

    /** A rectangle that fits on the page individually but runs off its edge. */
    public function test_a_field_that_runs_off_the_page_is_refused(): void
    {
        [, $issued] = $this->scenario();

        $this->postJson(self::BASE.'/create-and-send', [
            'name' => 'Synthetic overflowing field',
            'document' => base64_encode(PdfFixtures::bytes('single-page-letter')),
            'recipients' => [['first_name' => 'Dana', 'email' => 'dana@buyer.example.test', 'order' => 1]],
            'fields' => [[
                'type' => 'signature',
                'page_number' => 1,
                'position' => ['x' => 80.0, 'y' => 10.0, 'width' => 30.0, 'height' => 5.0],
            ]],
        ], FirmaFacadeScenario::headers($issued))
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_request');
    }

    /**
     * An anchored field is placed by finding its text in the document.
     *
     * `single-page-letter.pdf` draws "Signature:" whose run occupies x = 72, y = 582.4,
     * width = 72 in native space (asserted independently in
     * `tests/Feature/Preparation/PdfAnchorResolutionTest`). With a top-left origin and no
     * offset, the field's top-left corner is that corner.
     */
    public function test_an_anchored_field_is_placed_from_the_document_text(): void
    {
        [, $issued] = $this->scenario();

        $response = $this->postJson(self::BASE.'/create-and-send', [
            'name' => 'Synthetic anchored agreement',
            'document' => base64_encode(PdfFixtures::bytes('single-page-letter')),
            'recipients' => [['first_name' => 'Dana', 'email' => 'dana@buyer.example.test', 'order' => 1]],
            'fields' => [[
                'type' => 'signature',
                'page_number' => 1,
                'anchor' => ['text' => 'Signature:', 'occurrence' => 'sole'],
                // An anchor says where; the size is still the caller's, in percent.
                'position' => [
                    'x' => 0.0,
                    'y' => 0.0,
                    'width' => 170.0 / self::PAGE_WIDTH * 100,
                    'height' => 36.0 / self::PAGE_HEIGHT * 100,
                ],
            ]],
        ], FirmaFacadeScenario::headers($issued))->assertStatus(201);

        $envelope = Envelope::query()->where('public_id', $response->json('id'))->firstOrFail();
        $field = $envelope->fieldSchema()->fields[0];

        $this->assertEqualsWithDelta(72.0, $field->rect->x, 0.01);
        $this->assertEqualsWithDelta(582.4, $field->rect->y, 0.01);
        $this->assertEqualsWithDelta(170.0, $field->rect->width, 0.01);
        $this->assertEqualsWithDelta(36.0, $field->rect->height, 0.01);

        // Issue #23: the request is kept beside the rectangle it produced, with a receipt
        // naming the revision the text was located in. That is what tells the envelope's own
        // send-time resolution the work is already done for these exact bytes.
        $anchor = $field->anchor;
        $this->assertNotNull($anchor);
        $this->assertSame('Signature:', $anchor->text);
        $this->assertSame(AnchorPlacementMode::Replace, $anchor->mode());
        $this->assertTrue($anchor->required);

        $receipt = $anchor->resolved;
        $this->assertNotNull($receipt);
        $this->assertSame($envelope->document_sha256, $receipt->documentSha256);
        $this->assertSame(1, $receipt->page);
        $this->assertSame(1, $receipt->occurrenceIndex);
        $this->assertSame($field->rect->toArray(), $receipt->rect->toArray());
        // The receipt also records where the *text* was, which is not the field's rectangle.
        $this->assertSame(['x' => 72, 'y' => 582.4, 'width' => 72, 'height' => 12], $receipt->anchorRect->toArray());

        // And the send that create-and-send performed did not move any of it.
        $this->assertSame(
            hash('sha256', $envelope->fieldSchema()->canonicalJson()),
            $envelope->field_schema_sha256,
        );
        $this->assertNull($envelope->omitted_anchor_fields);
    }

    /**
     * A generated document has to declare the version it was generated as.
     *
     * The emitted anchor carries a `resolved` receipt, which is a 1.1 member. A document that
     * said 1.0 while containing one would be rejected by every consumer holding the unchanged
     * 1.0 contract — it forbids undeclared properties — and the envelope persists that document
     * verbatim, because send finds the receipt already matches its digest and rewrites nothing.
     */
    public function test_a_generated_schema_declares_the_version_it_was_generated_as(): void
    {
        [, $issued] = $this->scenario();

        $response = $this->postJson(self::BASE.'/create-and-send', [
            'name' => 'Synthetic anchored agreement',
            'document' => base64_encode(PdfFixtures::bytes('single-page-letter')),
            'recipients' => [['first_name' => 'Dana', 'email' => 'dana@buyer.example.test', 'order' => 1]],
            'fields' => [[
                'type' => 'signature',
                'page_number' => 1,
                'anchor' => ['text' => 'Signature:', 'occurrence' => 'sole'],
                'position' => [
                    'x' => 0.0,
                    'y' => 0.0,
                    'width' => 170.0 / self::PAGE_WIDTH * 100,
                    'height' => 36.0 / self::PAGE_HEIGHT * 100,
                ],
            ]],
        ], FirmaFacadeScenario::headers($issued))->assertStatus(201);

        $envelope = Envelope::query()->where('public_id', $response->json('id'))->firstOrFail();

        $this->assertSame(SchemaVersion::CURRENT, $envelope->field_schema['schema_version']);
        $this->assertNotNull($envelope->fieldSchema()->fields[0]->anchor?->resolved);

        // And it is a document the published contract for that version accepts.
        $this->assertTrue(
            (new FieldSchemaValidator)->validate($envelope->field_schema)->isValid(),
            'The stored schema must re-import cleanly; an envelope carries it on every request.',
        );
    }

    /**
     * The receipt has to name the bytes the text was actually located in.
     *
     * With `rebuild_pages` on, intake stores a *rewritten* review revision, so the upload and the
     * revision are two different PDFs. The page geometry has always come from the revision; if
     * the text came from the upload while the receipt claimed the revision's digest, send would
     * take that receipt as proof the work was already done and place fields using coordinates
     * measured in a document nobody signs.
     */
    public function test_an_anchor_is_resolved_in_the_review_revision_that_will_be_signed(): void
    {
        config()->set('esign.documents.normalization.rebuild_pages', true);

        [, $issued] = $this->scenario();

        $response = $this->postJson(self::BASE.'/create-and-send', [
            'name' => 'Synthetic anchored agreement',
            'document' => base64_encode(PdfFixtures::bytes('single-page-letter')),
            'recipients' => [['first_name' => 'Dana', 'email' => 'dana@buyer.example.test', 'order' => 1]],
            'fields' => [[
                'type' => 'signature',
                'page_number' => 1,
                'anchor' => ['text' => 'Signature:', 'occurrence' => 'sole'],
                'position' => [
                    'x' => 0.0,
                    'y' => 0.0,
                    'width' => 170.0 / self::PAGE_WIDTH * 100,
                    'height' => 36.0 / self::PAGE_HEIGHT * 100,
                ],
            ]],
        ], FirmaFacadeScenario::headers($issued))->assertStatus(201);

        $envelope = Envelope::query()->where('public_id', $response->json('id'))->firstOrFail();
        $revision = $envelope->documentRevision;
        $receipt = $envelope->fieldSchema()->fields[0]->anchor?->resolved;

        $this->assertNotNull($receipt);
        $this->assertNotNull($revision);

        // The revision is a rewrite of the upload, so its digest is its own.
        $this->assertNotSame(hash('sha256', PdfFixtures::bytes('single-page-letter')), $revision->sha256);

        // And the receipt names it, because that is the document the text was read from.
        $this->assertSame($revision->sha256, $receipt->documentSha256);
        $this->assertSame($envelope->document_sha256, $receipt->documentSha256);
    }

    /**
     * An anchor offset cannot push a field off the page.
     *
     * The percent path is bounded by `x + width <= 100`, but an anchored rectangle is built
     * from where the text turned out to be plus the caller's offset, so nothing before the
     * resolution knows whether it fits — and nothing after it checks either: the native
     * schema validator's page-fit rule needs `PageSizes`, which `FieldSchemaDocument` does
     * not supply on this path. Without an explicit check a field lands past the edge, is
     * drawn nowhere a signer can reach, and is then reported by `/fields` as a percentage
     * over 100 — a value this same facade refuses on the way in.
     */
    public function test_an_anchor_offset_that_leaves_the_page_is_refused(): void
    {
        [, $issued] = $this->scenario();

        $this->postJson(self::BASE.'/create-and-send', [
            'name' => 'Synthetic anchored off the page',
            'document' => base64_encode(PdfFixtures::bytes('single-page-letter')),
            'recipients' => [['first_name' => 'Dana', 'email' => 'dana@buyer.example.test', 'order' => 1]],
            'fields' => [[
                'type' => 'signature',
                'page_number' => 1,
                'anchor' => ['text' => 'Signature:', 'offset_x' => 90.0],
                'position' => ['x' => 0.0, 'y' => 0.0, 'width' => 27.0, 'height' => 4.5],
            ]],
        ], FirmaFacadeScenario::headers($issued))
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_request')
            ->assertJsonPath('details.page_number', 1);

        $this->assertSame(0, Envelope::query()->count());
    }

    /** An offset outside the percent range is refused before the document is even read. */
    public function test_an_anchor_offset_outside_the_percent_range_is_refused(): void
    {
        [, $issued] = $this->scenario();

        $this->postJson(self::BASE.'/create-and-send', [
            'name' => 'Synthetic anchored with a points offset',
            'document' => base64_encode(PdfFixtures::bytes('single-page-letter')),
            'recipients' => [['first_name' => 'Dana', 'email' => 'dana@buyer.example.test', 'order' => 1]],
            'fields' => [[
                'type' => 'signature',
                'page_number' => 1,
                // Reads as points, which is what a caller who skipped the docs would send.
                'anchor' => ['text' => 'Signature:', 'offset_y' => 900],
                'position' => ['x' => 0.0, 'y' => 0.0, 'width' => 27.0, 'height' => 4.5],
            ]],
        ], FirmaFacadeScenario::headers($issued))
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_request')
            ->assertJsonPath('details.property', 'anchor.offset_y');
    }

    /**
     * A non-numeric offset is refused, not cast to zero.
     *
     * `(float) "not-a-number"` is `0.0`, which would place the field at the bare anchor
     * origin and report success — the same silent reinterpretation the percent path already
     * refuses for `position.x`. There is no reason for the two coordinate inputs to fail
     * differently.
     */
    public function test_a_non_numeric_anchor_offset_is_refused_rather_than_coerced(): void
    {
        [, $issued] = $this->scenario();

        $this->postJson(self::BASE.'/create-and-send', [
            'name' => 'Synthetic anchored with a nonsense offset',
            'document' => base64_encode(PdfFixtures::bytes('single-page-letter')),
            'recipients' => [['first_name' => 'Dana', 'email' => 'dana@buyer.example.test', 'order' => 1]],
            'fields' => [[
                'type' => 'signature',
                'page_number' => 1,
                'anchor' => ['text' => 'Signature:', 'offset_x' => 'not-a-number'],
                'position' => ['x' => 0.0, 'y' => 0.0, 'width' => 27.0, 'height' => 4.5],
            ]],
        ], FirmaFacadeScenario::headers($issued))
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_request')
            ->assertJsonPath('details.property', 'anchor.offset_x');

        $this->assertSame(0, Envelope::query()->count());
    }

    /**
     * An anchor that matches nothing is an error, not a fallback position.
     */
    public function test_an_anchor_that_matches_nothing_is_refused(): void
    {
        [, $issued] = $this->scenario();

        $this->postJson(self::BASE.'/create-and-send', [
            'name' => 'Synthetic missing anchor',
            'document' => base64_encode(PdfFixtures::bytes('single-page-letter')),
            'recipients' => [['first_name' => 'Dana', 'email' => 'dana@buyer.example.test', 'order' => 1]],
            'fields' => [[
                'type' => 'signature',
                'page_number' => 1,
                'anchor' => ['text' => 'No such text anywhere in this document'],
                'position' => ['x' => 0.0, 'y' => 0.0, 'width' => 20.0, 'height' => 5.0],
            ]],
        ], FirmaFacadeScenario::headers($issued))
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_request');

        $this->assertSame(0, Envelope::query()->count());

        // The document *is* retained here, and that is intake's rule rather than an
        // oversight: an anchor can only be judged against the document's own text, so the
        // bytes had to be stored to reach this refusal, and the report of what was uploaded
        // is the only evidence an operator has when a sender reports a rejection. Nothing
        // references it; the blob pruner reclaims it.
        $this->assertSame(1, $this->ingestedCount());
    }

    /**
     * The whole call: the document is really ingested, the request is sent, and the response
     * carries the first signer's own link.
     */
    public function test_the_document_is_ingested_and_the_request_is_sent(): void
    {
        [, $issued] = $this->scenario();

        $response = $this->postJson(self::BASE.'/create-and-send', [
            'name' => 'Synthetic two-party agreement',
            'document' => base64_encode(PdfFixtures::bytes('single-page-letter')),
            'expiration_hours' => 72,
            'recipients' => [
                ['first_name' => 'Dana', 'last_name' => 'Buyer', 'email' => 'dana@buyer.example.test', 'order' => 1],
                ['first_name' => 'Sam', 'last_name' => 'Seller', 'email' => 'sam@seller.example.test', 'order' => 2],
            ],
            'fields' => [
                ['type' => 'signature', 'page_number' => 1, 'recipient_email' => 'dana@buyer.example.test', 'position' => ['x' => 10.0, 'y' => 80.0, 'width' => 25.0, 'height' => 4.0]],
                ['type' => 'signature', 'page_number' => 1, 'recipient_email' => 'sam@seller.example.test', 'position' => ['x' => 50.0, 'y' => 80.0, 'width' => 25.0, 'height' => 4.0]],
            ],
        ], FirmaFacadeScenario::headers($issued))->assertStatus(201);

        // `status` on this route is the string "sent" — a third status representation
        // alongside create's string and the detail response's object of booleans.
        $response->assertJsonPath('status', 'sent');
        $response->assertJsonPath('first_signer.email', 'dana@buyer.example.test');
        $response->assertJsonPath('credits_remaining', null);
        // The link is this application's own stable resolver, not `app.firma.dev`'s, and it
        // names the first signer's own recipient id — the only handle a signing session binds
        // to. A consumer must read this field instead of constructing a URL.
        $firstSigner = $response->json('first_signer.id');
        $this->assertSame(
            route('signing.legacy.show', ['recipient' => $firstSigner]),
            $response->json('first_signer.signing_link'),
        );
        $this->assertStringNotContainsString('firma.dev', (string) $response->json('first_signer.signing_link'));

        // Deliberately *not* a freshly minted invitation. A recipient has at most one live
        // invitation and issuing another revokes the previous one, so a link minted for this
        // response would either kill the one the invitation email carries or be killed by it.
        $this->assertSame(0, RecipientInvitation::query()->count());

        $envelope = Envelope::query()->where('public_id', $response->json('id'))->firstOrFail();
        $this->assertSame(EnvelopeState::Sent, $envelope->state);
        $this->assertSame(72, $envelope->expiration_hours);

        // The document went through the real intake: preflight ran, both revisions were
        // written, and the bytes are on the disk. `uploaded_by` is null, which is what marks
        // an API-ingested document apart from the scenario's own factory-made one: a service
        // credential is not a person and no user id is fabricated for it.
        $document = $this->ingested();
        $this->assertSame(1, $document->page_count);
        $this->assertNotNull($document->reviewRevision());
        Storage::disk('documents')->assertExists($document->original_path);

        // Retained byte-for-byte: the original revision's digest is the digest of what was
        // posted, not of a re-encoding of it.
        $this->assertSame(
            hash('sha256', PdfFixtures::bytes('single-page-letter')),
            $document->original_sha256,
        );

        // Two stages, in the order the request gave.
        $this->assertSame([['r1'], ['r2']], $envelope->fieldSchema()->signingOrder);
    }

    /**
     * A field that names no recipient this request declares is refused.
     *
     * Field ownership is by recipient, never by signing order and never by elimination
     * (`docs/HANDOFF.md` §2).
     */
    public function test_a_field_that_names_no_recipient_is_refused(): void
    {
        [, $issued] = $this->scenario();

        $this->postJson(self::BASE.'/create-and-send', [
            'name' => 'Synthetic unowned field',
            'document' => base64_encode(PdfFixtures::bytes('single-page-letter')),
            'recipients' => [
                ['first_name' => 'Dana', 'email' => 'dana@buyer.example.test', 'order' => 1],
                ['first_name' => 'Sam', 'email' => 'sam@seller.example.test', 'order' => 2],
            ],
            'fields' => [[
                'type' => 'signature',
                'page_number' => 1,
                'position' => ['x' => 10.0, 'y' => 80.0, 'width' => 25.0, 'height' => 4.0],
            ]],
        ], FirmaFacadeScenario::headers($issued))
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_request');
    }

    /** A recipient with no explicit order is refused (disagreement D3). */
    public function test_a_recipient_without_an_order_is_refused(): void
    {
        [, $issued] = $this->scenario();

        $this->postJson(self::BASE.'/create-and-send', [
            'name' => 'Synthetic unordered recipients',
            'document' => base64_encode(PdfFixtures::bytes('single-page-letter')),
            'recipients' => [['first_name' => 'Dana', 'email' => 'dana@buyer.example.test']],
            'fields' => [[
                'type' => 'signature',
                'page_number' => 1,
                'position' => ['x' => 10.0, 'y' => 80.0, 'width' => 25.0, 'height' => 4.0],
            ]],
        ], FirmaFacadeScenario::headers($issued))
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_request')
            ->assertJsonPath('details.recipient_index', 1);

        $this->assertSame(0, $this->ingestedCount());
    }

    /** An approver or a copy recipient is a 501, not a signer by another name. */
    public function test_a_non_signer_designation_is_not_implemented(): void
    {
        [, $issued] = $this->scenario();

        $this->postJson(self::BASE.'/create-and-send', [
            'name' => 'Synthetic with an approver',
            'document' => base64_encode(PdfFixtures::bytes('single-page-letter')),
            'recipients' => [['first_name' => 'Dana', 'email' => 'dana@buyer.example.test', 'order' => 1, 'designation' => 'Approver']],
            'fields' => [[
                'type' => 'signature',
                'page_number' => 1,
                'position' => ['x' => 10.0, 'y' => 80.0, 'width' => 25.0, 'height' => 4.0],
            ]],
        ], FirmaFacadeScenario::headers($issued))
            ->assertStatus(501)
            ->assertJsonPath('error', 'unsupported')
            ->assertJsonPath('details.unsupported_option', 'recipients[].designation=Approver');

        $this->assertSame(0, $this->ingestedCount());
    }

    /**
     * A field type this profile declares and this build cannot place is a 501 naming it.
     *
     * Disagreement D10: upstream has five disagreeing field-type enums. A type that cannot
     * land on a native type is refused rather than coerced into a text box, because a field a
     * signer was never asked to complete is worse than a rejected request.
     */
    public function test_a_declared_but_unimplemented_field_type_is_not_implemented(): void
    {
        [, $issued] = $this->scenario();

        $this->postJson(self::BASE.'/create-and-send', [
            'name' => 'Synthetic with a file field',
            'document' => base64_encode(PdfFixtures::bytes('single-page-letter')),
            'recipients' => [['first_name' => 'Dana', 'email' => 'dana@buyer.example.test', 'order' => 1]],
            'fields' => [[
                'type' => 'radio_buttons',
                'page_number' => 1,
                'position' => ['x' => 10.0, 'y' => 80.0, 'width' => 25.0, 'height' => 4.0],
            ]],
        ], FirmaFacadeScenario::headers($issued))
            ->assertStatus(501)
            ->assertJsonPath('error', 'unsupported')
            ->assertJsonPath('details.unsupported_option', 'fields[].type=radio_buttons');

        $this->assertSame(0, $this->ingestedCount());
    }

    /**
     * The chosen unsupported option, asserted where a caller meets it.
     *
     * `hand_drawn_only` is a real upstream setting. This build's signature capture accepts a
     * typed *or* a drawn signature, so there is no drawn-only mode to switch on; accepting the
     * flag would mean recording an agreement under an assurance the sender did not get.
     */
    public function test_hand_drawn_only_is_not_implemented(): void
    {
        [, $issued] = $this->scenario();

        $this->postJson(self::BASE.'/create-and-send', [
            'name' => 'Synthetic drawn-only agreement',
            'document' => base64_encode(PdfFixtures::bytes('single-page-letter')),
            'recipients' => [['first_name' => 'Dana', 'email' => 'dana@buyer.example.test', 'order' => 1]],
            'fields' => [[
                'type' => 'signature',
                'page_number' => 1,
                'position' => ['x' => 10.0, 'y' => 80.0, 'width' => 25.0, 'height' => 4.0],
            ]],
            'settings' => ['hand_drawn_only' => true],
        ], FirmaFacadeScenario::headers($issued))
            ->assertStatus(501)
            ->assertJsonPath('error', 'unsupported')
            ->assertJsonPath('details.unsupported_option', 'settings.hand_drawn_only')
            ->assertJsonPath('details.profile', 'firma-compat-v1');

        // Fail closed: the option is refused before the document is even ingested, so
        // nothing was created, nothing was stored, and nobody was written to.
        $this->assertSame(0, Envelope::query()->count());
        $this->assertSame(0, $this->ingestedCount());
    }

    /**
     * `settings.require_otp_verification` is honoured, not refused.
     *
     * Mailbox OTP is per-envelope (`envelopes.require_otp`), so this one *can* be granted —
     * and a requested identity check that is not performed would be exactly the silent
     * downgrade AGENTS.md forbids, so granting it is the only alternative to a 501.
     */
    public function test_require_otp_verification_is_honoured(): void
    {
        [, $issued] = $this->scenario();

        $response = $this->postJson(self::BASE.'/create-and-send', [
            'name' => 'Synthetic OTP agreement',
            'document' => base64_encode(PdfFixtures::bytes('single-page-letter')),
            'recipients' => [['first_name' => 'Dana', 'email' => 'dana@buyer.example.test', 'order' => 1]],
            'fields' => [[
                'type' => 'signature',
                'page_number' => 1,
                'position' => ['x' => 10.0, 'y' => 80.0, 'width' => 25.0, 'height' => 4.0],
            ]],
            'settings' => ['require_otp_verification' => true],
        ], FirmaFacadeScenario::headers($issued))->assertStatus(201);

        $envelope = Envelope::query()->where('public_id', $response->json('id'))->firstOrFail();
        $this->assertTrue($envelope->require_otp);
        $this->assertTrue(app(OtpRequirement::class)->for($envelope));

        // The polling response reports the *resolved* requirement, so a caller sees what its
        // guests will actually be asked for.
        $this->assertTrue($this->getJson(
            self::BASE.'/'.$envelope->public_id,
            FirmaFacadeScenario::headers($issued),
        )->assertOk()->json('settings.require_otp_verification'));
    }

    /**
     * Saying nothing is not the same as saying false.
     *
     * `envelopes.require_otp` stays null so the envelope inherits its workspace and then the
     * deployment default, which is the whole reason the column is a nullable boolean.
     */
    public function test_omitting_the_otp_setting_leaves_the_envelope_undecided(): void
    {
        [, $issued] = $this->scenario();

        $response = $this->postJson(self::BASE.'/create-and-send', [
            'name' => 'Synthetic agreement with no OTP preference',
            'document' => base64_encode(PdfFixtures::bytes('single-page-letter')),
            'recipients' => [['first_name' => 'Dana', 'email' => 'dana@buyer.example.test', 'order' => 1]],
            'fields' => [[
                'type' => 'signature',
                'page_number' => 1,
                'position' => ['x' => 10.0, 'y' => 80.0, 'width' => 25.0, 'height' => 4.0],
            ]],
        ], FirmaFacadeScenario::headers($issued))->assertStatus(201);

        $envelope = Envelope::query()->where('public_id', $response->json('id'))->firstOrFail();
        $this->assertNull($envelope->require_otp);
    }

    /** Upstream's separate `anchor_tags[]` collection is out of profile. */
    public function test_the_anchor_tags_collection_is_not_implemented(): void
    {
        [, $issued] = $this->scenario();

        $this->postJson(self::BASE.'/create-and-send', [
            'name' => 'Synthetic anchor tags',
            'document' => base64_encode(PdfFixtures::bytes('single-page-letter')),
            'recipients' => [['first_name' => 'Dana', 'email' => 'dana@buyer.example.test', 'order' => 1]],
            'fields' => [[
                'type' => 'signature',
                'page_number' => 1,
                'position' => ['x' => 10.0, 'y' => 80.0, 'width' => 25.0, 'height' => 4.0],
            ]],
            'anchor_tags' => [['anchor_text' => 'Signature:', 'offset_units' => 'pixels']],
        ], FirmaFacadeScenario::headers($issued))
            ->assertStatus(501)
            ->assertJsonPath('details.unsupported_option', 'anchor_tags');
    }

    /** An encrypted PDF is refused by the real preflight parser, with its findings. */
    public function test_an_encrypted_document_is_refused_before_anything_is_created(): void
    {
        [, $issued] = $this->scenario();

        $this->postJson(self::BASE.'/create-and-send', [
            'name' => 'Synthetic encrypted agreement',
            'document' => base64_encode(PdfFixtures::bytes('encrypted-aes128')),
            'recipients' => [['first_name' => 'Dana', 'email' => 'dana@buyer.example.test', 'order' => 1]],
            'fields' => [[
                'type' => 'signature',
                'page_number' => 1,
                'position' => ['x' => 10.0, 'y' => 80.0, 'width' => 25.0, 'height' => 4.0],
            ]],
        ], FirmaFacadeScenario::headers($issued))
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_request')
            ->assertJsonStructure(['error', 'message', 'details' => ['findings']]);

        // The document row is kept with its report — that is intake's rule, and it is the
        // only evidence of what was uploaded — but no signing request exists.
        $this->assertSame(0, Envelope::query()->count());
        $this->assertSame(1, $this->ingestedCount());
    }

    /**
     * A storage failure is a 500, is reported, and leaks nothing.
     *
     * `DocumentStorageException` messages interpolate the disk name, the object path, and the
     * driver's own error text, so this is the one exception on the create path that must not
     * reach a body. It is deliberately *not* in `FirmaErrorMap`: it falls to the default arm,
     * which answers a fixed sentence and a `500`. A `400` would be worse than the leak on its
     * own — it would have the caller retry a body that was never wrong — and a mapped
     * exception would also be classified as an expected refusal and dropped from the error
     * log, so a storage outage would vanish.
     *
     * Asserted with `app.debug` off, the way
     * `Tests\Feature\Integration\Native\NativeApiErrorShapeTest` does: the debug arm adds
     * the exception class and message on purpose for a failing local test, and production is
     * the configuration the promise is about.
     */
    public function test_a_storage_failure_is_an_internal_error_that_leaks_nothing(): void
    {
        [, $issued] = $this->scenario();

        config(['app.debug' => false]);

        // A disk whose writes fail the way an S3-compatible store fails. Swapped at the
        // filesystem manager, so the real DocumentBlobStore builds the real
        // DocumentStorageException — path, disk name, driver text and all.
        $filesystems = Mockery::mock(FilesystemManager::class);
        $filesystems->shouldReceive('disk')->andThrow(
            new RuntimeException('AccessDenied for bucket esign-prod-docs'),
        );
        $this->app->instance(DocumentBlobStore::class, new DocumentBlobStore($filesystems));

        $response = $this->postJson(self::BASE.'/create-and-send', [
            'name' => 'Synthetic agreement with a broken disk',
            'document' => base64_encode(PdfFixtures::bytes('single-page-letter')),
            'recipients' => [['first_name' => 'Dana', 'email' => 'dana@buyer.example.test', 'order' => 1]],
            'fields' => [[
                'type' => 'signature',
                'page_number' => 1,
                'position' => ['x' => 10.0, 'y' => 80.0, 'width' => 25.0, 'height' => 4.0],
            ]],
        ], FirmaFacadeScenario::headers($issued));

        $response->assertStatus(500)->assertJsonPath('error', 'internal_error');

        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('esign-prod-docs', $body);
        $this->assertStringNotContainsString('AccessDenied', $body);
        $this->assertStringNotContainsString('.pdf', $body);
        $this->assertStringNotContainsString('DocumentStorageException', $body);
    }

    /** Not base64 at all. */
    public function test_a_document_that_is_not_base64_is_refused(): void
    {
        [, $issued] = $this->scenario();

        $this->postJson(self::BASE.'/create-and-send', [
            'name' => 'Synthetic broken document',
            'document' => '!!!! not base64 !!!!',
            'recipients' => [['first_name' => 'Dana', 'email' => 'dana@buyer.example.test', 'order' => 1]],
            'fields' => [[
                'type' => 'signature',
                'page_number' => 1,
                'position' => ['x' => 10.0, 'y' => 80.0, 'width' => 25.0, 'height' => 4.0],
            ]],
        ], FirmaFacadeScenario::headers($issued))
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_request');
    }

    /** Both sources, or neither, is a 400: the request does not say which agreement is meant. */
    public function test_a_document_and_a_template_together_are_refused(): void
    {
        [$scenario, $issued] = $this->scenario();
        $version = $scenario->publishedTemplate();

        $this->postJson(self::BASE.'/create-and-send', [
            'name' => 'Synthetic ambiguous source',
            'document' => base64_encode(PdfFixtures::bytes('single-page-letter')),
            'template_id' => $version->template->public_id,
            'recipients' => [['first_name' => 'Dana', 'email' => 'dana@buyer.example.test', 'order' => 1]],
        ], FirmaFacadeScenario::headers($issued))
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_request');
    }

    /**
     * The document this facade ingested, as opposed to the scenario's factory-made one.
     *
     * `uploaded_by` is null for an API upload: a service credential is not a person, and
     * intake records the credential in the audit trail rather than inventing a user id.
     */
    private function ingested(): Document
    {
        return Document::query()->whereNull('uploaded_by')->sole();
    }

    private function ingestedCount(): int
    {
        return Document::query()->whereNull('uploaded_by')->count();
    }

    /**
     * @return array{0: FirmaFacadeScenario, 1: IssuedServiceCredential}
     */
    private function scenario(): array
    {
        $scenario = FirmaFacadeScenario::create();

        return [$scenario, $scenario->credential()];
    }
}
