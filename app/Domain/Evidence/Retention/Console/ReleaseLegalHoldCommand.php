<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Retention\Console;

use App\Domain\Evidence\Retention\Exceptions\RetentionRefused;
use App\Domain\Evidence\Retention\LegalHold;
use App\Domain\Identity\Audit\AuditActor;
use App\Domain\Signing\Models\Envelope;
use Illuminate\Console\Command;

/**
 * Releases a legal hold.
 *
 * `--reason` is required here for a reason that is easy to miss: releasing is the half that
 * enables deletion. A hold that was cleared with no recorded basis is indistinguishable
 * from one somebody cleared by accident, and the row itself forgets — the release clears
 * `legal_hold_at`, `legal_hold_reason`, and `legal_hold_by`, so the audit event is the only
 * remaining record that the envelope was ever held, when, and why.
 *
 * Releasing does not delete anything. It only stops the hold from excluding the envelope
 * from the next `esign:retention:run`, which then applies whatever policy is configured —
 * and on a default deployment that policy is "never delete executed documents".
 */
final class ReleaseLegalHoldCommand extends Command
{
    protected $signature = 'esign:hold:release
        {envelope : The envelope public id}
        {--reason= : Why the hold is being released. Required and recorded.}';

    protected $description = 'Release the legal hold on an envelope';

    public function handle(LegalHold $holds): int
    {
        $publicId = (string) $this->argument('envelope');
        $reason = (string) $this->option('reason');

        $envelope = Envelope::query()->withTrashed()->where('public_id', $publicId)->first();

        if ($envelope === null) {
            $this->error('No envelope with public id '.$publicId.'.');

            return self::FAILURE;
        }

        $heldSince = $envelope->legal_hold_at;

        try {
            $holds->release($envelope, $reason, AuditActor::console('esign:hold:release'));
        } catch (RetentionRefused $refusal) {
            $this->error($refusal->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'The legal hold on envelope %s%s has been released. Retention policies apply to it again.',
            $envelope->public_id,
            $heldSince === null ? '' : ', in place since '.$heldSince->toIso8601String().',',
        ));

        return self::SUCCESS;
    }
}
