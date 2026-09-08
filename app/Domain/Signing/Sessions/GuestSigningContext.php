<?php

declare(strict_types=1);

namespace App\Domain\Signing\Sessions;

use App\Domain\Signing\Envelopes\EnvelopeState;
use App\Domain\Signing\Envelopes\RecipientState;
use App\Domain\Signing\Models\Envelope;
use App\Domain\Signing\Models\EnvelopeRecipient;
use App\Domain\Signing\Sessions\Models\SigningSession;

/**
 * Who is acting, on what, under which session — resolved once per request.
 *
 * `RequireSigningSession` builds one and binds it into the container, so a controller and
 * its Form Request see the same three rows and neither re-resolves them from the URL. That
 * matters more than it saves: a Form Request that looked the recipient up again could reach
 * a different one than the controller acts on, and the gap between those two lookups is
 * exactly where an authorization check stops meaning anything.
 *
 * The three rows are always read fresh by the middleware. Recipient state is the ordering
 * guard, and a cached copy would let somebody act after another tab had already signed for
 * them.
 */
final readonly class GuestSigningContext
{
    public function __construct(
        public Envelope $envelope,
        public EnvelopeRecipient $recipient,
        public SigningSession $session,
    ) {}

    /** The opaque reference the attestation records for this session. */
    public function sessionRef(): string
    {
        return $this->session->public_id;
    }

    public function mayAct(): bool
    {
        return $this->recipient->state === RecipientState::Active && $this->envelopeIsOpen();
    }

    /**
     * True while the envelope is somewhere a recipient can still act.
     *
     * The state machine's `accept` and `submit_values` are legal from `sent` and
     * `in_progress` and nowhere else (docs/signing/state-machine.md), so this reproduces
     * that list rather than inventing a second one. It is a *presentation* check: the state
     * machine still refuses the write, and this only decides whether to render a form whose
     * submission is guaranteed to fail.
     */
    public function envelopeIsOpen(): bool
    {
        return in_array($this->envelope->state, [EnvelopeState::Sent, EnvelopeState::InProgress], true);
    }
}
