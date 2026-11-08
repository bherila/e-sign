<?php

declare(strict_types=1);

namespace App\Domain\Signing\Envelopes;

use App\Domain\Signing\Models\Envelope;
use App\Domain\Signing\Sessions\OtpRequirement;
use Illuminate\Contracts\Config\Repository;

/**
 * Whether a signer on this envelope is offered Decline, and Finish later (issue #165).
 *
 * Three levels, most specific first: the envelope, the workspace that sent it, then the
 * deployment default (`esign.signing.allow_decline`, `esign.signing.show_save_for_later`, both
 * true). A template's values reach an envelope by being copied onto it at creation, so there
 * is no template level here: what an agreement offers is decided when it is built, and a later
 * template edit cannot change it. Null at a level means "not decided here", exactly as for
 * `require_otp` ({@see OtpRequirement}).
 *
 * `allowDecline()` false is not only a hidden button. {@see EnvelopeStateMachine::decline()}
 * refuses the transition, so no surface can decline an agreement that does not allow it, and
 * the only way to stop it is to cancel it (AGENTS.md, "One state machine").
 *
 * A workspace change applies to every envelope whose own value is null, including envelopes
 * already sent — the same reading upstream documents.
 */
final readonly class SignerButtons
{
    public function __construct(private ?Repository $config = null) {}

    public function allowDecline(Envelope $envelope): bool
    {
        return $this->resolve($envelope, 'allow_decline');
    }

    public function showSaveForLater(Envelope $envelope): bool
    {
        return $this->resolve($envelope, 'show_save_for_later');
    }

    private function resolve(Envelope $envelope, string $column): bool
    {
        $own = $envelope->getAttribute($column);

        if ($own !== null) {
            return (bool) $own;
        }

        $workspace = $envelope->workspace()->first();
        $inherited = $workspace?->getAttribute($column);

        if ($inherited !== null) {
            return (bool) $inherited;
        }

        $config = $this->config ?? app(Repository::class);

        return (bool) $config->get('esign.signing.'.$column, true);
    }
}
