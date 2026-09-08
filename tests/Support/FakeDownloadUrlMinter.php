<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Delivery\Events\DownloadUrlMinter;
use App\Domain\Signing\Models\Envelope;

/**
 * A download-link minter for tests.
 *
 * Deliberately unconditional: it returns a URL for any envelope it is asked about, so the
 * "a completion notice links to the document only once one exists" test proves the gate is in
 * `EnvelopeMailScheduler` rather than in an implementation that happens to be careful.
 */
final class FakeDownloadUrlMinter implements DownloadUrlMinter
{
    public function downloadUrlFor(Envelope $envelope): ?string
    {
        return 'https://esign.example.test/agreements/'.$envelope->public_id.'/download';
    }
}
