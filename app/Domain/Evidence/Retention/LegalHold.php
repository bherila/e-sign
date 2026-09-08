<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Retention;

use App\Domain\Evidence\Retention\Exceptions\LegalHoldActive;
use App\Domain\Evidence\Retention\Exceptions\RetentionRefused;
use App\Domain\Identity\Audit\AuditActor;
use App\Domain\Identity\Audit\AuditRecorder;
use App\Domain\Signing\Models\Envelope;
use Carbon\CarbonImmutable;

/**
 * Places and releases the application's own deletion restriction on one envelope.
 *
 * ## What a hold here is, and what it is not
 *
 * It is a column that every deletion path in this module consults, plus a pair of audit
 * events. It is **not** bucket-enforced legal hold and **not** WORM: Garage's documented S3
 * implementation offers neither Object Lock nor object versioning, and AGENTS.md forbids
 * describing the storage as though it did. Anyone with database access and a willingness to
 * write SQL can clear the column. What the hold actually buys is that no *routine* path —
 * no scheduled sweep, no privacy erasure, no blob purge — will remove a held agreement, and
 * that both the placement and the release are on the record. Resistance to a determined
 * deletion comes from independently administered off-host copies, which is why
 * docs/operations/backups.md exists.
 *
 * ## Why placing twice is refused
 *
 * A second placement would rewrite `legal_hold_at`, and that timestamp is frequently the
 * fact somebody later needs: when the instruction to preserve arrived. Re-placing is
 * therefore an error naming the existing hold rather than a silent no-op or an overwrite.
 * Releasing a hold that is not there is refused for the mirror-image reason — an operator
 * who thinks they just released a hold, and did not, will act on that belief.
 *
 * ## Why a reason is mandatory both ways
 *
 * The release is the interesting half. A hold with no recorded reason for its release is a
 * hold that looks like it expired on its own, and the next reader cannot tell an authorized
 * release from a mistake. Both commands require `--reason`.
 */
final readonly class LegalHold
{
    public const PLACED = 'retention.legal_hold.placed';

    public const RELEASED = 'retention.legal_hold.released';

    /** Matches `envelopes.legal_hold_reason`; the column, not the sentence, is the limit. */
    public const MAX_REASON_LENGTH = 500;

    public function __construct(private AuditRecorder $audit) {}

    public function isHeld(Envelope $envelope): bool
    {
        return $envelope->legal_hold_at !== null;
    }

    /**
     * @throws LegalHoldActive
     */
    public function assertNotHeld(Envelope $envelope): void
    {
        if ($this->isHeld($envelope)) {
            throw LegalHoldActive::forEnvelope($envelope->public_id, $envelope->legal_hold_reason);
        }
    }

    /**
     * @param  string|null  $placedBy  A short actor label stored on the row so a listing can
     *                                 show who held it without joining the audit table. The
     *                                 authoritative actor is on the audit event.
     *
     * @throws RetentionRefused When a hold is already in place, or the reason is empty.
     */
    public function place(Envelope $envelope, string $reason, AuditActor $actor, ?string $placedBy = null): Envelope
    {
        $reason = $this->normalizeReason($reason);

        if ($this->isHeld($envelope)) {
            throw new RetentionRefused(sprintf(
                'Envelope %s has been under legal hold since %s (%s). Release it before placing a new '
                .'hold; overwriting the existing one would destroy the record of when preservation '
                .'was first required.',
                $envelope->public_id,
                $envelope->legal_hold_at?->toIso8601String() ?? 'an unrecorded time',
                $envelope->legal_hold_reason ?? 'no reason recorded',
            ));
        }

        $placedAt = CarbonImmutable::now();

        $envelope->legal_hold_at = $placedAt;
        $envelope->legal_hold_reason = $reason;
        $envelope->legal_hold_by = $placedBy ?? $actor->label;
        $envelope->save();

        $this->audit->record($actor, self::PLACED, $envelope, [
            'envelope' => $envelope->public_id,
            'workspace_id' => $envelope->workspace_id,
            'state' => $envelope->state->value,
            'reason' => $reason,
            'held_by' => $envelope->legal_hold_by,
            'placed_at' => $placedAt->toIso8601String(),
        ]);

        return $envelope;
    }

    /**
     * @throws RetentionRefused When no hold is in place, or the reason is empty.
     */
    public function release(Envelope $envelope, string $reason, AuditActor $actor): Envelope
    {
        $reason = $this->normalizeReason($reason);

        if (! $this->isHeld($envelope)) {
            throw new RetentionRefused(sprintf(
                'Envelope %s is not under legal hold, so there is nothing to release.',
                $envelope->public_id,
            ));
        }

        $heldSince = $envelope->legal_hold_at;
        $heldReason = $envelope->legal_hold_reason;
        $heldBy = $envelope->legal_hold_by;

        $envelope->legal_hold_at = null;
        $envelope->legal_hold_reason = null;
        $envelope->legal_hold_by = null;
        $envelope->save();

        // Everything the cleared columns held is copied into the event, because clearing
        // them is the only place that information goes: the row no longer knows that the
        // envelope was ever held, and this is the record that it was.
        $this->audit->record($actor, self::RELEASED, $envelope, [
            'envelope' => $envelope->public_id,
            'workspace_id' => $envelope->workspace_id,
            'state' => $envelope->state->value,
            'reason' => $reason,
            'held_since' => $heldSince?->toIso8601String(),
            'held_reason' => $heldReason,
            'held_by' => $heldBy,
            'released_at' => CarbonImmutable::now()->toIso8601String(),
        ]);

        return $envelope;
    }

    /**
     * @throws RetentionRefused
     */
    private function normalizeReason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new RetentionRefused(
                'A legal hold needs a reason. An unexplained hold is one the next operator cannot '
                .'safely release, and an unexplained release is one nobody can distinguish from a '
                .'mistake.',
            );
        }

        return mb_substr($reason, 0, self::MAX_REASON_LENGTH);
    }
}
