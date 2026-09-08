<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Events;

use App\Domain\Signing\Models\Envelope;

/**
 * The default binding until a download surface exists: no link, and the message still goes.
 *
 * Silence is the honest answer here. There is no authorized download route for a guest
 * recipient yet, and the completion notice's value — "this is executed" — does not depend on
 * carrying a link (`App\Mail\CompletedMail`).
 */
final class UnconfiguredDownloadUrlMinter implements DownloadUrlMinter
{
    public function downloadUrlFor(Envelope $envelope): ?string
    {
        return null;
    }
}
