<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Events;

use App\Domain\Signing\Envelopes\EnvelopeState;
use App\Domain\Signing\Models\Envelope;
use App\Domain\Signing\Models\EnvelopeRecipient;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * The `data` object every `signing_request.*` event carries.
 *
 * One builder, used by the event sink today and by the Firma-compatible facade later, so a
 * receiver polling `GET /signing-requests/{id}` and a receiver reading a webhook body see
 * the same facts described the same way. Two independent projections of one state machine
 * is exactly the drift AGENTS.md forbids.
 *
 * Shapes come from `docs/compatibility/firma-capability-matrix.md`:
 *
 * - `status` is an **object of booleans** (`sent`, `finished`, `cancelled`, `declined`,
 *   `expired`), not a string. Upstream documents three different status representations and
 *   says several flags can be true at once for a terminal state; the consumer polls the
 *   boolean form, so that is the form the event carries.
 * - `timestamps` uses the `_on` suffix of `SigningRequestDetail`, not the `_date` suffix of
 *   the create response. Both exist upstream and neither may be normalised into the other.
 * - `recipients[]` uses the `SigningRequestUser` vocabulary, including `finished_on`.
 *
 * Three deliberate differences, all recorded in the matrix:
 *
 * - **`download` is ours and it is never a URL.** A webhook body is stored once, signed
 *   once, and replayed for up to ~40 h, so a link minted when the event was recorded is
 *   either expired by the time it is read or long-lived enough to be a credential sitting in
 *   a receiver's log. It is `null` until a validated artifact is published and then reports
 *   only that one exists; the bytes come from the download endpoint, authorized per request.
 * - **`first_name` / `last_name` are absent.** This product stores one display name and
 *   splitting it would be a guess about a person's name (`docs/HANDOFF.md` §2 keeps identity
 *   facts unguessed). `name` and `email` are emitted verbatim.
 * - **`designation` is always `Signer`.** There is no approver or CC concept here yet, and
 *   inventing one per recipient would put an authority in the payload that nothing enforces.
 */
final class SigningRequestPayload
{
    /**
     * Upstream's designation enum is `Signer|Approver|CC`. Everyone we model signs.
     */
    public const DESIGNATION = 'Signer';

    /**
     * The full envelope-scoped payload: the request, every recipient, and the workspace.
     *
     * @return array<string, mixed>
     */
    public static function for(Envelope $envelope): array
    {
        return [
            'signing_request' => self::signingRequest($envelope),
            'recipients' => array_map(self::recipient(...), self::recipients($envelope)),
            'workspace' => self::workspace($envelope),
        ];
    }

    /**
     * The recipient-scoped payload for `signing_request.recipient.*`.
     *
     * `recipients` carries the one recipient the event is about rather than the whole list.
     * The matrix annotates those two events with `data.recipients[]` for exactly this
     * reason: a receiver handling "who just signed" should not have to diff two arrays to
     * find out.
     *
     * @return array<string, mixed>
     */
    public static function forRecipient(Envelope $envelope, EnvelopeRecipient $recipient): array
    {
        return [
            'signing_request' => self::signingRequest($envelope),
            'recipients' => [self::recipient($recipient)],
            'workspace' => self::workspace($envelope),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function signingRequest(Envelope $envelope): array
    {
        return [
            'id' => $envelope->public_id,
            'name' => $envelope->title,
            // Upstream's name for the workspace an agreement belongs to, and a required
            // field of SigningRequestDetail. There is no company above a workspace here.
            'companies_workspaces_id' => $envelope->workspace?->public_id,
            'status' => self::status($envelope),
            'timestamps' => self::timestamps($envelope),
            'expiration_hours' => $envelope->expiration_hours,
            'expires_at' => self::at($envelope->expires_at),
            'download' => self::download($envelope),
        ];
    }

    /**
     * The boolean projection. Overlapping flags are intended: a cancelled envelope that was
     * sent reports both, which is what upstream means by "multiple can be true for terminal
     * states".
     *
     * @return array<string, bool>
     */
    public static function status(Envelope $envelope): array
    {
        return [
            'sent' => $envelope->sent_at !== null,
            'finished' => $envelope->state === EnvelopeState::Completed,
            'cancelled' => $envelope->state === EnvelopeState::Cancelled,
            'declined' => $envelope->state === EnvelopeState::Declined,
            'expired' => $envelope->state === EnvelopeState::Expired,
        ];
    }

    /**
     * `finished_on` is the completion timestamp and not the last signature: this product
     * publishes completion only once a validated final PDF is retrievable, so the two are
     * genuinely different moments and `last_signing_action_on` is where the later signature
     * shows up.
     *
     * @return array<string, string|null>
     */
    public static function timestamps(Envelope $envelope): array
    {
        return [
            'created_on' => self::at($envelope->created_at),
            'sent_on' => self::at($envelope->sent_at),
            'finished_on' => self::at($envelope->completed_at),
            'cancelled_on' => self::at($envelope->cancelled_at),
            'declined_on' => self::at($envelope->declined_at),
            'last_changed_on' => self::at($envelope->updated_at),
            'last_signing_action_on' => self::at(self::lastSigningAction($envelope)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function recipient(EnvelopeRecipient $recipient): array
    {
        return [
            'id' => $recipient->public_id,
            'name' => $recipient->name,
            'email' => $recipient->email,
            'designation' => self::DESIGNATION,
            'order' => $recipient->order_index,
            'finished_on' => self::at($recipient->signed_at),
            'declined_on' => self::at($recipient->declined_at),
            'decline_reason' => $recipient->decline_reason,
        ];
    }

    /**
     * Whether a validated artifact exists, and nothing else. See the class docblock for why
     * there is no URL here.
     *
     * @return array<string, mixed>|null
     */
    private static function download(Envelope $envelope): ?array
    {
        if ($envelope->artifact_ref === null) {
            return null;
        }

        return [
            'available' => true,
            // Never a snapshot of a half-executed agreement: this product publishes one
            // artifact, after everyone has signed.
            'is_partial' => false,
            'generated_on' => self::at($envelope->completed_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function workspace(Envelope $envelope): array
    {
        return [
            'id' => $envelope->workspace?->public_id,
            'name' => $envelope->workspace?->name,
        ];
    }

    /**
     * Prefers an already-loaded relation, so building a payload costs one query rather than
     * three and so a caller holding the envelope in memory — a unit test, or the facade after
     * an eager load — can build one with no database at all.
     *
     * @return list<EnvelopeRecipient>
     */
    private static function recipients(Envelope $envelope): array
    {
        if (! $envelope->relationLoaded('recipients')) {
            $envelope->setRelation('recipients', $envelope->recipients()->get());
        }

        /** @var Collection<int, EnvelopeRecipient> $recipients */
        $recipients = $envelope->getRelation('recipients');

        return array_values($recipients->all());
    }

    private static function lastSigningAction(Envelope $envelope): ?CarbonInterface
    {
        $latest = null;

        foreach (self::recipients($envelope) as $recipient) {
            foreach ([$recipient->signed_at, $recipient->declined_at] as $moment) {
                if ($moment !== null && ($latest === null || $moment->greaterThan($latest))) {
                    $latest = $moment;
                }
            }
        }

        return $latest;
    }

    private static function at(?CarbonInterface $moment): ?string
    {
        return $moment?->toIso8601String();
    }
}
