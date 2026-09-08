<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Finalization\Exceptions;

/**
 * Raised when the publishing transaction finds the world has moved on: the envelope is no
 * longer `finalizing`, a newer generation has started, or an artifact is already published.
 *
 * This is a race resolving correctly (docs/ARCHITECTURE.md invariant 6), not a fault. The
 * losing attempt records itself as failed and publishes nothing; it must NOT push the
 * envelope into `finalization_failed`, because the state it found is the legal outcome —
 * completed by the other attempt, or cancelled by the sender.
 */
final class PublicationSuperseded extends FinalizationException {}
