<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Schema;

use App\Domain\Preparation\Templates\TemplateService;
use App\Domain\Signing\Envelopes\EnvelopeSourceSnapshot;

/**
 * Refuses the two anchor options that promise behaviour this build does not yet perform.
 *
 * **This whole class is temporary and is deleted by the PR that lands send-time anchor
 * resolution** (`feat/anchor-resolution-at-send`, piece D of the stack that replaced #101).
 * `AnchorResolutionGateTest` asserts it still exists and names that PR, so it cannot quietly
 * outlive its reason: a gate that stays after the thing it was waiting for has arrived is its own
 * kind of lie.
 *
 * ## What it refuses, and why only these two
 *
 * Schema 1.1 adds four anchor members. Two are inert without a resolver — `tolerance` is a number
 * a future check will read, and `resolved` is a receipt describing a measurement — and storing
 * either changes nothing about what a signer sees. The other two *promise* something:
 *
 * - `placement: "cross_check"` says the anchor will be checked against the declared rectangle and
 *   a disagreement will stop the send. Nothing checks it yet, so a sender is told their placement
 *   is verified when it is not.
 * - `anchor.required: false` says an absent anchor omits the field. Nothing omits it yet, so the
 *   field is placed at its placeholder rectangle instead — a box appearing where the sender was
 *   told none would.
 *
 * Accepting either is a **successful no-op of an unsupported option**, which AGENTS.md forbids,
 * and on a legal document a sender told a check is in force is worse off than one told it is
 * unavailable.
 *
 * ## Where it runs
 *
 * At the two places a caller's field schema is *written*: template versions
 * ({@see TemplateService}) and envelope snapshots
 * ({@see EnvelopeSourceSnapshot}). Deliberately not inside
 * {@see FieldSchemaValidator}, which defines the published contract: 1.1 *is* valid, and a
 * document using these members is a correct 1.1 document that this deployment declines to accept.
 * Keeping the two separate means reading a stored document back never trips the gate, and the
 * contract's own tests are not written against a temporary deployment state.
 */
final class AnchorResolutionGate
{
    /** The branch whose merge deletes this class. Named in the test that pins the gate. */
    public const LIFTED_BY = 'feat/anchor-resolution-at-send';

    /**
     * Every gated option this document uses, as validation errors.
     *
     * @param  array<string, mixed>  $document
     * @return list<ValidationError>
     */
    public static function refusals(array $document): array
    {
        $errors = [];
        $fields = $document['fields'] ?? null;

        if (! is_array($fields)) {
            return $errors;
        }

        foreach ($fields as $index => $field) {
            $anchor = is_array($field) ? ($field['anchor'] ?? null) : null;

            if (! is_array($anchor)) {
                continue;
            }

            $path = '/fields/'.$index.'/anchor';

            if (($anchor['placement'] ?? null) === AnchorPlacementMode::CrossCheck->value) {
                $errors[] = new ValidationError(
                    $path.'/placement',
                    ValidationCode::AnchorResolutionUnavailable,
                    'anchor.placement "'.AnchorPlacementMode::CrossCheck->value.'" needs anchor resolution, which '
                        .'this deployment does not perform yet. A cross-check promises that the anchor text will be '
                        .'located and compared with the rectangle you declared, and that a disagreement will stop '
                        .'the send — accepting it while nothing checks anything would tell you your placement was '
                        .'verified when it was not. Use "'.AnchorPlacementMode::Replace->value.'", or place the '
                        .'field by rectangle alone, until resolution ships.',
                );
            }

            if (($anchor['required'] ?? null) === false) {
                $errors[] = new ValidationError(
                    $path.'/required',
                    ValidationCode::AnchorResolutionUnavailable,
                    'anchor.required false needs anchor resolution, which this deployment does not perform yet. It '
                        .'promises that a field whose anchor text is absent will be left off the document; nothing '
                        .'omits it yet, so the field would be placed at its declared rectangle instead — a box '
                        .'appearing where you were told none would. Omit the property, or leave the field out, '
                        .'until resolution ships.',
                );
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $document
     *
     * @throws InvalidFieldSchemaException
     */
    public static function assertAvailable(array $document): void
    {
        $errors = self::refusals($document);

        if ($errors !== []) {
            throw new InvalidFieldSchemaException(new ValidationResult($errors));
        }
    }
}
