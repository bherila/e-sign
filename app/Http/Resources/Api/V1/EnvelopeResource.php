<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Signing\Models\Envelope;
use App\Domain\Signing\Models\EnvelopeRecipient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An envelope, as an integration polls it.
 *
 * `state` is the native lifecycle from docs/signing/state-machine.md, spelled exactly as the
 * enum spells it. It is a single string and not a set of booleans: the compatibility facade
 * owes the consumer's boolean status fields (docs/HANDOFF.md section 10) and translates for
 * them, and reproducing that shape here would put an unrelated vendor's ergonomics on our
 * own API forever.
 *
 * Two of these fields are load-bearing and easy to skim past:
 *
 * - **`version`** is the envelope's compare-and-swap token, not a document version. It moves
 *   on every transition, and a client that read an envelope, decided something, and wants to
 *   act on that decision safely can hand it back.
 * - **`content_frozen_at`** is the moment material content stopped being editable — the
 *   first acceptance, or send in parallel mode. After it, a prefill correction is refused
 *   rather than applied, so a caller can tell in advance which of its edits will be taken.
 *
 * `omitted_anchor_fields` is the third: it is normally empty, and when it is not it says that a
 * field the source declared was deliberately not placed, because its anchor declared that its
 * text may legitimately be absent and it was.
 *
 * The digests are published deliberately: `document_sha256` and `field_schema_sha256` are
 * what every attestation binds, so a caller can prove the envelope it is looking at is built
 * from the document and field set it thinks it is. No disk name and no storage path appear
 * here or anywhere else on this API.
 *
 * @mixin Envelope
 */
class EnvelopeResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Envelope $envelope */
        $envelope = $this->resource;

        return [
            'id' => $envelope->public_id,
            'title' => $envelope->title,
            'state' => $envelope->state->value,
            'version' => $envelope->version,

            'signing_mode' => $envelope->signing_mode->value,
            'assurance_level' => $envelope->assurance_level->value,
            'consent_policy_version' => $envelope->consent_policy_version,

            'source_template_version_id' => $envelope->source_template_version_id,
            'document_sha256' => $envelope->document_sha256,
            'field_schema_sha256' => $envelope->field_schema_sha256,

            'expires_in_hours' => $envelope->expiration_hours,
            'expires_at' => $envelope->expires_at?->toIso8601String(),
            'content_frozen_at' => $envelope->content_frozen_at?->toIso8601String(),

            'cancel_reason' => $envelope->cancel_reason,
            'finalization_failure_reason' => $envelope->finalization_failure_reason,

            // Empty for almost every envelope. When it is not, each entry names a field that
            // was declared and then intentionally left out because its optional anchor was not
            // in the document — the one narrow compatibility option in docs/HANDOFF.md
            // section 7, published here so a reader can tell an intentional omission from a
            // field that went missing. See docs/preparation/anchors.md.
            'omitted_anchor_fields' => $envelope->omittedAnchorFields(),

            'created_at' => $envelope->created_at?->toIso8601String(),
            'sent_at' => $envelope->sent_at?->toIso8601String(),
            'completed_at' => $envelope->completed_at?->toIso8601String(),
            'cancelled_at' => $envelope->cancelled_at?->toIso8601String(),
            'declined_at' => $envelope->declined_at?->toIso8601String(),
            'expired_at' => $envelope->expired_at?->toIso8601String(),

            'recipients' => $envelope->relationLoaded('recipients')
                ? array_map(
                    static fn (EnvelopeRecipient $recipient): array => RecipientResource::make($recipient)
                        ->resolve($request),
                    array_values($envelope->recipients->all()),
                )
                : [],
        ];
    }
}
