<?php

declare(strict_types=1);

namespace App\Domain\Signing\Contracts;

use App\Domain\Preparation\Anchoring\AnchorResolutionFailed;
use App\Domain\Preparation\Anchoring\AnchorResolutionOutcome;
use App\Domain\Signing\Envelopes\EnvelopeAnchorResolution;
use App\Domain\Signing\Models\Envelope;

/**
 * Where the state machine turns an envelope's anchors into stored rectangles.
 *
 * A port for the same reason {@see AssurancePolicyCheck} is one: the send gate has to consult
 * something that reads a PDF, and the Signing module must not learn how to. It is expressed the
 * way the module boundary runs — Signing knows Preparation, Preparation knows nothing about
 * envelopes (docs/ARCHITECTURE.md) — so the implementation lives here, in Signing, and
 * delegates to `Preparation\Anchoring`.
 *
 * The default binding is {@see EnvelopeAnchorResolution}. There is deliberately no null
 * implementation: an envelope whose anchors were not resolved is an envelope whose fields have
 * no agreed position, and quietly sending one is exactly the failure this whole path exists to
 * prevent.
 */
interface AnchorResolution
{
    /**
     * Resolve every anchor in the envelope's copied field schema that is not already resolved
     * against the envelope's own document bytes.
     *
     * Called inside the send transaction, before anything is written. It must not write to the
     * envelope: the caller commits the resolved schema together with the transition, so that a
     * rolled-back send cannot leave a half-resolved field set behind.
     *
     * @throws AnchorResolutionFailed With every unplaceable field at once.
     */
    public function forEnvelope(Envelope $envelope): AnchorResolutionOutcome;
}
