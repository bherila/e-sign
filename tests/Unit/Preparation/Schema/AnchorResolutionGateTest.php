<?php

declare(strict_types=1);

namespace Tests\Unit\Preparation\Schema;

use App\Domain\Preparation\Schema\AnchorResolutionGate;
use App\Domain\Preparation\Schema\InvalidFieldSchemaException;
use App\Domain\Preparation\Schema\ValidationCode;
use PHPUnit\Framework\TestCase;
use Tests\Support\FieldSchemaFixture;

/**
 * The gate that refuses anchor options this build cannot honour, and the note of when it goes.
 *
 * Two anchor members *promise* behaviour: `placement: "cross_check"` promises the anchor will be
 * checked against the declared rectangle and a disagreement will stop the send, and
 * `anchor.required: false` promises an absent anchor leaves its field off the document. Until the
 * resolver lands, nothing does either — so accepting them would be a successful no-op of an
 * unsupported option, which AGENTS.md forbids, on a document people sign.
 *
 * The last case here exists to make the gate mortal. A gate that stays after the thing it was
 * waiting for has arrived is its own kind of lie, and the cheapest way for that to happen is for
 * nobody to remember it. This test names the branch that deletes it, so lifting the gate is one
 * deletion with one failing test pointing at it rather than a hunt.
 */
final class AnchorResolutionGateTest extends TestCase
{
    public function test_a_cross_check_placement_is_refused_with_its_own_reason(): void
    {
        $document = FieldSchemaFixture::asArray();
        $document['fields'][5]['anchor']['placement'] = 'cross_check';
        $document['fields'][5]['anchor']['tolerance'] = 2;

        $errors = AnchorResolutionGate::refusals($document);

        $this->assertCount(1, $errors);
        $this->assertSame(ValidationCode::AnchorResolutionUnavailable, $errors[0]->code);
        $this->assertSame('/fields/5/anchor/placement', $errors[0]->path);
        // The message has to say the option is unavailable, not that the document is wrong: a
        // sender told "invalid" goes and changes something that was never the problem.
        $this->assertStringContainsString('does not perform yet', $errors[0]->message);
        $this->assertStringContainsString('until resolution ships', $errors[0]->message);
    }

    public function test_an_optional_anchor_is_refused_with_its_own_reason(): void
    {
        $document = FieldSchemaFixture::asArray();
        $document['fields'][9]['anchor']['required'] = false;

        $errors = AnchorResolutionGate::refusals($document);

        $this->assertCount(1, $errors);
        $this->assertSame(ValidationCode::AnchorResolutionUnavailable, $errors[0]->code);
        $this->assertSame('/fields/9/anchor/required', $errors[0]->path);
        $this->assertStringContainsString('does not perform yet', $errors[0]->message);
    }

    /** The members that promise nothing are not gated: storing them changes nothing a signer sees. */
    public function test_the_inert_members_are_not_gated(): void
    {
        $document = FieldSchemaFixture::asArray();
        $document['fields'][5]['anchor']['resolved'] = [
            'document_sha256' => str_repeat('d', 64),
            'page' => 2,
            'occurrence_index' => 1,
            'anchor_rect' => ['x' => 330, 'y' => 622.4, 'width' => 165.6, 'height' => 12],
            'rect' => $document['fields'][5]['rect'],
        ];

        $this->assertSame([], AnchorResolutionGate::refusals($document));
    }

    public function test_an_ordinary_document_passes_the_gate(): void
    {
        $this->assertSame([], AnchorResolutionGate::refusals(FieldSchemaFixture::asArray()));
    }

    public function test_it_raises_the_same_exception_as_any_other_import_failure(): void
    {
        $document = FieldSchemaFixture::asArray();
        $document['fields'][9]['anchor']['required'] = false;

        $this->expectException(InvalidFieldSchemaException::class);

        AnchorResolutionGate::assertAvailable($document);
    }

    /**
     * The refusal has to reach the caller as a code, not only inside a sentence.
     *
     * The native API maps `InvalidFieldSchemaException` to a `problems[]` list carrying each
     * pointer and code. `EnvelopeSourceSnapshot` therefore runs the gate *outside* the catch that
     * wraps ordinary schema failures in `invalid_field_schema`: flattening the gate's refusal into
     * that generic reason would leave a caller reading "your snapshot is invalid" with nothing to
     * branch on and no idea which option to drop.
     */
    public function test_the_refusal_survives_as_structured_errors(): void
    {
        $document = FieldSchemaFixture::asArray();
        $document['fields'][5]['anchor']['placement'] = 'cross_check';
        $document['fields'][5]['anchor']['tolerance'] = 2;

        try {
            AnchorResolutionGate::assertAvailable($document);
            $this->fail('Expected the gated option to be refused.');
        } catch (InvalidFieldSchemaException $refused) {
            $this->assertSame(
                [['path' => '/fields/5/anchor/placement', 'code' => 'anchor_resolution_unavailable']],
                array_map(
                    static fn ($error): array => ['path' => $error->path, 'code' => $error->code->value],
                    $refused->errors(),
                ),
            );
        }
    }

    /**
     * The gate must not outlive its reason.
     *
     * When `feat/anchor-resolution-at-send` lands, delete `AnchorResolutionGate`, its two call
     * sites in `TemplateService` and `EnvelopeSourceSnapshot`, the
     * `anchor_resolution_unavailable` code in both projections, the note in the two gated members'
     * descriptions in `field-schema-1.1.json`, and this file.
     */
    public function test_the_gate_names_the_branch_that_deletes_it(): void
    {
        $this->assertSame('feat/anchor-resolution-at-send', AnchorResolutionGate::LIFTED_BY);
    }
}
