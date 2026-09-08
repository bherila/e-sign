<?php

declare(strict_types=1);

namespace App\Domain\Signing\Envelopes;

use App\Domain\Signing\Models\Envelope;

/**
 * What a batch of submitted values changed.
 *
 * `envelopeVersion` and `materialValuesSha256` are the two things a signing session needs
 * next: they are exactly what {@see AcceptanceRequest} has to state to prove the person
 * accepted what they were shown. Returning them here is what keeps a normal
 * submit-then-accept sequence from failing its own staleness check — the caller submits,
 * takes these two values, displays the document, and accepts against them.
 */
final readonly class ValueSubmissionResult
{
    /**
     * @param  list<string>  $fieldIds  The schema field ids written, in the order given.
     */
    public function __construct(
        public Envelope $envelope,
        public array $fieldIds,
        public string $materialValuesSha256,
        public int $envelopeVersion,
    ) {}
}
