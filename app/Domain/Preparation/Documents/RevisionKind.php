<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Documents;

/**
 * The kinds of immutable revision a document can have.
 *
 * The value is part of the storage key, so renaming a case orphans stored objects.
 */
enum RevisionKind: string
{
    /**
     * The upload, byte for byte. Never re-rendered, re-sealed, or overwritten
     * (AGENTS.md, "Retain originals byte-for-byte").
     */
    case Original = 'original';

    /**
     * What a signer is shown and what final assembly builds on. Equal to the original
     * unless a disclosed normalization step ran; either way the revision records which.
     */
    case Review = 'review';
}
