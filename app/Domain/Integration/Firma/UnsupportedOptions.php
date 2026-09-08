<?php

declare(strict_types=1);

namespace App\Domain\Integration\Firma;

/**
 * The gate that turns an option this build cannot honour into a `501`.
 *
 * `docs/HANDOFF.md` section 10: "Never advertise a successful no-op for an unsupported route
 * or required option." A Form Request cannot express this rule, because the values are
 * perfectly valid — `hand_drawn_only: true` is a boolean in the right place — and the reason
 * for refusing is that this deployment cannot do the thing, not that the body is malformed.
 * That is a `501` with the option named, not a `422`.
 *
 * ## What is refused, and why each one
 *
 * | Option | Why |
 * |---|---|
 * | `settings.hand_drawn_only: true` | Signature capture offers typed **or** drawn (`RenderSettings::$signatureAppearance`). There is no mode that refuses a typed signature, so promising one would mean accepting an agreement captured under assurance the sender did not ask for. |
 * | `settings.require_otp_verification: true` | Step-up verification is not bound to this surface. A request for a stronger identity check that is not performed is exactly the "silent downgrade" `AGENTS.md` forbids. |
 * | `settings.allow_editing_before_sending: true` | A sent envelope's content is frozen; a correction is a new envelope with renewed signatures. |
 * | `settings.attach_pdf_on_finish: true` | The completion mail carries an authorized link, not the bytes. Attaching an executed agreement to an email takes it outside the boundary that authorizes it. |
 * | `settings.identity_editable_fields` (non-empty) | A recipient cannot rewrite their own identity here: it is recorded in the attestation. |
 * | `notify_signers: false` on cancel | Withdrawal notices go to everyone who was written to, decided by `EnvelopeAudience`, not by the caller. Accepting `false` and mailing anyway would be a lie in the response. |
 * | `reminders[]` | Reminder scheduling is a console command with a fixed policy, not a per-request schedule. |
 * | `language`, `completion_title`, `completion_message`, `completion_redirect_url`, `completion_redirect_delay` | The signing UI is this application's own and visibly branded as this product (`docs/HANDOFF.md` section 10). Accepting copy for a page we do not render would be a no-op. |
 * | PATCH properties form | `name`, `expiration_hours` and `template_description` on an existing request: the title is part of the immutable snapshot (`Envelope::SNAPSHOT_COLUMNS`), so it cannot be rewritten after the envelope is built. |
 *
 * Everything here is refused only when the caller **asks for it**. Sending
 * `hand_drawn_only: false` is not a request for anything, and `null` is not either; both
 * pass. That distinction is the difference between a facade that fails closed and one that
 * is unusable.
 */
final class UnsupportedOptions
{
    /**
     * Settings whose *true* value cannot be honoured, mapped to the sentence explaining it.
     *
     * @var array<string, string>
     */
    private const REFUSED_WHEN_TRUE = [
        'hand_drawn_only' => 'Signature capture in this build accepts a typed or a drawn signature; there is no '
            .'drawn-only mode. Accepting this option would mean recording an agreement under an assurance the '
            .'sender did not get.',
        'require_otp_verification' => 'One-time-passcode verification is not bound to the signing flow in this '
            .'build. A request for a stronger identity check than the service performs is an error, never a '
            .'silent downgrade.',
        'allow_editing_before_sending' => 'A signing request cannot be edited once it has been built from its '
            .'document: the snapshot is immutable, and a correction is a new request with renewed signatures.',
        'attach_pdf_on_finish' => 'The completion email carries an authorized download link rather than the '
            .'executed PDF, so that the bytes stay behind a request this service authorizes.',
    ];

    /**
     * Request members that are refused whenever they are present and non-empty.
     *
     * @var array<string, string>
     */
    private const REFUSED_WHEN_PRESENT = [
        'reminders' => 'Reminders are sent by this service on its own schedule and are not configurable per '
            .'signing request.',
        'language' => 'The signing pages are served by this application in its own locale; a requested '
            .'language would not change what a signer sees.',
        'completion_title' => 'The completion page is this application\'s own and visibly branded as this '
            .'product; supplied copy would not be rendered.',
        'completion_message' => 'The completion page is this application\'s own and visibly branded as this '
            .'product; supplied copy would not be rendered.',
        'completion_redirect_url' => 'This service does not redirect a signer to a third-party URL after '
            .'signing.',
        'completion_redirect_delay' => 'This service does not redirect a signer to a third-party URL after '
            .'signing.',
        'anchor_tags' => 'Anchor tags are supplied per field as `fields[].anchor`, which this profile '
            .'resolves against the document text. The separate `anchor_tags[]` collection, with its own '
            .'offset units and its own type enum, is not implemented.',
    ];

    /**
     * @param  array<string, mixed>  $body  The validated request body.
     *
     * @throws FirmaException
     */
    public static function guard(array $body): void
    {
        foreach (self::REFUSED_WHEN_PRESENT as $key => $reason) {
            $value = $body[$key] ?? null;

            if ($value !== null && $value !== '' && $value !== []) {
                throw FirmaException::unsupported($key, $reason);
            }
        }

        $settings = is_array($body['settings'] ?? null) ? $body['settings'] : [];

        foreach (self::REFUSED_WHEN_TRUE as $key => $reason) {
            if (($settings[$key] ?? null) === true) {
                throw FirmaException::unsupported('settings.'.$key, $reason);
            }
        }

        $editable = $settings['identity_editable_fields'] ?? null;

        if ($editable !== null && $editable !== [] && $editable !== '') {
            throw FirmaException::unsupported(
                'settings.identity_editable_fields',
                'A recipient cannot rewrite their own identity on this service: who signed is recorded in '
                .'their attestation, and an editable identity field would let that record be changed after '
                .'the fact.',
            );
        }
    }

    /**
     * `notify_signers: false` on cancel.
     *
     * @throws FirmaException
     */
    public static function guardCancelNotification(?bool $notifySigners): void
    {
        if ($notifySigners === false) {
            throw FirmaException::unsupported(
                'notify_signers',
                'A withdrawal notice goes to everyone who was already written to, and to nobody who was not. '
                .'Which parties those are is decided by this service, so suppressing the notice is not an '
                .'option a caller can set; accepting it and mailing anyway would make the response untrue.',
            );
        }
    }

    /**
     * The PATCH properties form.
     *
     * @param  list<string>  $properties  The property names present in the body.
     *
     * @throws FirmaException
     */
    public static function guardPatchedProperties(array $properties): void
    {
        if ($properties === []) {
            return;
        }

        throw FirmaException::unsupported(
            'PATCH '.implode(', ', $properties),
            'A signing request\'s own properties cannot be changed after it has been built. Its title, its '
            .'document and its field schema are one immutable snapshot, which is what lets an executed '
            .'agreement be proved against what the parties were shown. Only a field value or a recipient\'s '
            .'contact details can be patched, and only while the request is a draft.',
            ['patchable' => ['field', 'recipient']],
        );
    }
}
