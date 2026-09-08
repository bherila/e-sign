<?php

declare(strict_types=1);

namespace App\Domain\Integration\Firma;

use App\Domain\Signing\Envelopes\SigningMode;
use App\Domain\Signing\Models\Envelope;
use App\Domain\Signing\Sessions\OtpRequirement;

/**
 * The `settings` object, and the five deprecated `0`/`1` integers beside it.
 *
 * Disagreement D5: `SigningRequestSettings` types `allow_download`,
 * `attach_pdf_on_finish`, `hand_drawn_only`, `use_signing_order` and
 * `allow_editing_before_sending` as booleans, while `SigningRequestDetail` repeats all five
 * at its top level as `integer` enum `[0, 1]`, marked deprecated. Both are emitted, each
 * with its own type, because the recorded responses carry both and a consumer reading
 * `response.use_signing_order` and a consumer reading `response.settings.use_signing_order`
 * are both in the wild.
 *
 * ## What each key actually reports
 *
 * Three of the fourteen describe something this product models:
 *
 * - `use_signing_order` — true when the envelope's signing mode is sequential. This is a
 *   real fact about the agreement and the only one of the five deprecated integers that
 *   varies.
 * - `allow_download` — true. Every party to an agreement here can fetch the executed PDF
 *   through an authorized download; there is no mode in which they cannot.
 * - `require_otp_verification` — the *resolved* answer from
 *   {@see OtpRequirement}: this envelope's own setting, then its workspace's, then the
 *   deployment default. Reported rather than echoed back, so a caller that set nothing sees
 *   what its guests will actually be asked for. It is honestly labelled elsewhere as a
 *   second check on the same factor — continued access to the mailbox the link went to —
 *   and never as a second factor or an eIDAS assurance (`docs/HANDOFF.md` §9).
 *
 * The rest report `false` or `null` and each one says which it is and why. `null` is used
 * where upstream's own recorded value is null — "this workspace has expressed no preference"
 * — and `false` where the behaviour genuinely does not happen. Neither is a placeholder:
 * inventing `true` for a setting nothing enforces would be exactly the "successful no-op"
 * AGENTS.md rules out, one read at a time.
 *
 * Requesting one of them is a different matter from reading it. {@see UnsupportedOptions} is
 * where an inbound `hand_drawn_only: true` becomes a `501` rather than a setting that was
 * accepted and ignored.
 */
final readonly class SigningRequestSettings
{
    public function __construct(private OtpRequirement $otp) {}

    /**
     * The five keys `SigningRequestDetail` repeats at its top level as `0`/`1` integers.
     *
     * @var list<string>
     */
    public const DEPRECATED_INTEGER_KEYS = [
        'use_signing_order',
        'allow_download',
        'allow_editing_before_sending',
        'hand_drawn_only',
        'attach_pdf_on_finish',
    ];

    /**
     * @return array<string, bool|string|null>
     */
    public function for(Envelope $envelope): array
    {
        return [
            // Modelled: every party can fetch the executed PDF through an authorized route.
            'allow_download' => true,

            // Not modelled: there is no "let a signer download the original before signing"
            // switch here. Null rather than false, matching the recorded value: the
            // workspace has expressed no preference, it has not opted out.
            'allow_presigning_download' => null,

            // Never. A sent envelope's content is frozen and a correction is a new envelope
            // with renewed signatures (docs/HANDOFF.md section 7).
            'allow_editing_before_sending' => false,

            // The completion mail carries an authorized link, never the PDF itself: an
            // executed agreement attached to an email leaves the boundary that authorizes it
            // (docs/BLOB_STORAGE.md).
            'attach_pdf_on_finish' => false,

            // False, and truthfully so: `RenderSettings::$signatureAppearance` records a
            // preference and nothing in the signing flow enforces it, so there is no
            // drawn-only mode. Asking for one is a 501, not this false.
            'hand_drawn_only' => false,

            // Modelled.
            'use_signing_order' => $envelope->signing_mode === SigningMode::Sequential,

            // Modelled: the mail outbox sends each of these, and there is no per-envelope
            // switch to suppress one. Reporting true is accurate for every envelope.
            'send_signing_email' => true,
            'send_finish_email' => true,
            'send_expiration_email' => true,
            'send_cancellation_email' => true,

            // Modelled, and resolved rather than echoed: envelope, then workspace, then the
            // deployment default.
            'require_otp_verification' => $this->otp->for($envelope),

            // Not modelled.
            'disable_guided_navigation' => null,

            // Not modelled: a recipient cannot edit their own identity here, because
            // identity facts are recorded in the attestation and never guessed
            // (docs/HANDOFF.md section 2).
            'identity_editable_fields' => null,
            'notify_identity_change_email' => false,
        ];
    }

    /**
     * The deprecated top-level integers, `0` or `1`, read off the same projection.
     *
     * @return array<string, int>
     */
    public function deprecatedIntegers(Envelope $envelope): array
    {
        $settings = $this->for($envelope);
        $integers = [];

        foreach (self::DEPRECATED_INTEGER_KEYS as $key) {
            $integers[$key] = ($settings[$key] ?? false) === true ? 1 : 0;
        }

        return $integers;
    }
}
