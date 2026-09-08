<?php

declare(strict_types=1);

namespace App\Http\Controllers\Signing;

use App\Domain\Preparation\Schema\FieldType;
use App\Domain\Signing\Capture\SignatureImage;
use App\Domain\Signing\Capture\SignatureImageRejected;
use App\Domain\Signing\Envelopes\EnvelopeStateMachine;
use App\Domain\Signing\Exceptions\FieldSubmissionRejected;
use App\Domain\Signing\Exceptions\IllegalTransition;
use App\Domain\Signing\Exceptions\RecipientNotEligible;
use App\Domain\Signing\Exceptions\StaleEnvelope;
use App\Domain\Signing\Sessions\GuestSigningContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Signing\SubmitSigningValuesRequest;
use Illuminate\Http\JsonResponse;

/**
 * POST /sign/{envelope}/session/values — the signer saves what they have filled in.
 *
 * Nothing here signs anything. It writes field values, and the response hands back the two
 * numbers the acceptance will have to quote: the envelope's new version and the material
 * digest as it now stands. That is exactly what `ValueSubmissionResult` exists to return,
 * and passing them forward is what keeps a normal fill-then-sign sequence from failing its
 * own staleness check.
 *
 * ## The one transformation
 *
 * Signature and initials values arrive as `data:` URLs and are decoded, measured, and
 * re-encoded as PNG by {@see SignatureImage} *before* they reach the state machine. That
 * ordering matters: `FieldValueValidator` checks only that a signature value is a string of
 * a plausible length, and storing the submitted bytes would put whatever else was in the
 * file — metadata, an ICC profile, a polyglot payload — into the sealed PDF.
 *
 * Every other type is passed through untouched and normalised by the state machine, so there
 * is one definition of what a date or a checkbox is.
 *
 * ## Why JSON
 *
 * The signing page saves as the signer works and again before the consent panel, so this
 * endpoint is called several times per session and must not navigate. The two endpoints that
 * *decide* something — accept and decline — are ordinary form posts that redirect, because
 * their outcome is a page and they must work when a fetch fails halfway.
 */
class SigningValuesController extends Controller
{
    public function __construct(
        private readonly EnvelopeStateMachine $machine,
        private readonly SignatureImage $images,
    ) {}

    public function store(
        SubmitSigningValuesRequest $request,
        GuestSigningContext $context,
    ): JsonResponse {
        if (! $context->mayAct()) {
            // The state machine would refuse this too. Answering here means the page gets a
            // reason it can show rather than a generic failure.
            return response()->json([
                'error' => 'not_eligible',
                'message' => 'This agreement is not currently open for you to complete.',
            ], 409);
        }

        try {
            $values = $this->reencodeSignatures($context, $request->values());
        } catch (SignatureImageRejected $rejected) {
            return response()->json([
                'error' => $rejected->reason,
                'message' => $rejected->getMessage(),
            ], 422);
        }

        try {
            $result = $this->machine->submitValues($context->recipient, $values);
        } catch (FieldSubmissionRejected $rejected) {
            return response()->json([
                'error' => $rejected->code(),
                'message' => $rejected->getMessage(),
            ], 422);
        } catch (RecipientNotEligible|IllegalTransition|StaleEnvelope $refused) {
            return response()->json([
                'error' => $refused->code(),
                'message' => $refused->getMessage(),
            ], 409);
        }

        return response()->json([
            'field_ids' => $result->fieldIds,
            // The page carries these two straight into the acceptance form. They are the
            // review this signer will be bound to (docs/ARCHITECTURE.md invariant 2).
            'reviewed' => [
                'material_values_sha256' => $result->materialValuesSha256,
                'envelope_version' => $result->envelopeVersion,
            ],
        ]);
    }

    /**
     * Replace every signature and initials value with bytes this application produced.
     *
     * Keyed by the schema's declared type rather than by inspecting the value, so a text
     * field containing a `data:` URL is stored as the text it is and a signature field
     * containing plain text is refused as the non-image it is. Guessing from the value would
     * make the treatment depend on what was sent.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     *
     * @throws SignatureImageRejected
     */
    private function reencodeSignatures(GuestSigningContext $context, array $values): array
    {
        $schema = $context->envelope->fieldSchema();

        foreach ($values as $fieldId => $value) {
            $field = $schema->field((string) $fieldId);

            if ($field === null) {
                continue;
            }

            if (! in_array($field->type, [FieldType::Signature, FieldType::Initials], true)) {
                continue;
            }

            if (! is_string($value)) {
                throw SignatureImageRejected::notADataUrl();
            }

            $values[$fieldId] = $this->images->reencode($value);
        }

        return $values;
    }
}
