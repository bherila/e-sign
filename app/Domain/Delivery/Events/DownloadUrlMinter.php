<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Events;

use App\Domain\Signing\Models\Envelope;

/**
 * Where a completion message's download link comes from, when there is one.
 *
 * The mirror image of {@see SigningUrlMinter}, and deliberately not the same interface. A
 * missing signing link makes an invitation a lie, so that port throws. A missing download
 * link makes a completion notice slightly less useful, so this one returns null:
 * `App\Mail\CompletedMail` already states that the URL is optional and that completion is
 * worth reporting even where a deployment has no download surface for the party in question.
 *
 * An implementation must return null — never a URL — while no validated artifact is
 * published. AGENTS.md allows completion to be announced only after the final PDF is
 * generated, validated, durably stored, and retrievable, and a link mailed a moment early is
 * a broken link in a message nobody can unsend.
 */
interface DownloadUrlMinter
{
    public function downloadUrlFor(Envelope $envelope): ?string;
}
