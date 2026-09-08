<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Retention\Console;

use App\Domain\Evidence\Retention\Exceptions\RetentionRefused;
use App\Domain\Evidence\Retention\LegalHold;
use App\Domain\Identity\Audit\AuditActor;
use App\Domain\Signing\Models\Envelope;
use Illuminate\Console\Command;

/**
 * Places the application's own deletion restriction on one envelope.
 *
 * A console command and not an HTTP route, deliberately. A hold is placed in response to
 * something happening outside the application — a dispute, a preservation notice, an
 * investigation — by whoever administers the deployment, and it is exactly the operation
 * that must not be reachable by a compromised workspace session. It is also the operation
 * whose audit trail matters most, and `AuditActor::console()` records the command that ran
 * rather than pretending somebody was signed in.
 *
 * The envelope is looked up by public ULID, and by ULID including soft-deleted rows: an
 * agreement retention has already scheduled for destruction is precisely the one somebody
 * runs this against during the grace period, and refusing to find it would be the worst
 * possible time to be pedantic. Placing the hold does not by itself undo the soft delete —
 * the operator is told to clear it — but it does stop `esign:retention:purge-blobs`
 * removing the bytes in the meantime.
 */
final class PlaceLegalHoldCommand extends Command
{
    protected $signature = 'esign:hold:place
        {envelope : The envelope public id}
        {--reason= : Why the agreement must be preserved. Required and recorded.}';

    protected $description = 'Place a legal hold on an envelope so no deletion path may remove it';

    public function handle(LegalHold $holds): int
    {
        $publicId = (string) $this->argument('envelope');
        $reason = (string) $this->option('reason');

        $envelope = Envelope::query()->withTrashed()->where('public_id', $publicId)->first();

        if ($envelope === null) {
            $this->error('No envelope with public id '.$publicId.'.');

            return self::FAILURE;
        }

        try {
            $holds->place($envelope, $reason, AuditActor::console('esign:hold:place'));
        } catch (RetentionRefused $refusal) {
            $this->error($refusal->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Envelope %s is under legal hold. No retention sweep, blob purge, or privacy erasure will '
            .'touch it until the hold is released.',
            $envelope->public_id,
        ));

        if ($envelope->deleted_at !== null) {
            $this->warn(sprintf(
                'This envelope was already soft-deleted by retention at %s. The hold stops its bytes '
                .'being purged, but the agreement stays hidden until an operator clears deleted_at.',
                $envelope->deleted_at->toIso8601String(),
            ));
        }

        return self::SUCCESS;
    }
}
