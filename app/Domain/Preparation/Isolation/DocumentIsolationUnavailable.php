<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Isolation;

use App\Domain\Preparation\Contracts\DocumentReadUnavailable;

/**
 * Process isolation is required (`esign.documents.isolation.mode=process`) and this host cannot
 * provide it.
 *
 * A server configuration failure, not a statement about any document: it is thrown rather than
 * reported as a rejection, so a sender is never told to fix a file that is fine. It is the
 * service-side read failure every port declares, and the one kind of it a retry alone cannot cure.
 */
final class DocumentIsolationUnavailable extends DocumentReadUnavailable
{
    public function isTransient(): bool
    {
        return false;
    }
}
