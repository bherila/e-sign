<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Retention;

use App\Domain\Evidence\Finalization\Artifacts\ArtifactStore;
use App\Domain\Evidence\Finalization\Exceptions\ArtifactStorageException;
use App\Domain\Identity\Audit\AuditActor;
use App\Domain\Identity\Audit\AuditRecorder;
use App\Domain\Signing\Models\Envelope;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * The second half of executed-document retention: removing the bytes, once the decision has
 * had time to be wrong.
 *
 * ## Why the bytes are not removed in the first pass
 *
 * Because the first pass is where the mistake happens. A misconfigured
 * `executed_documents_days`, a clock that is wrong, a policy set to 30 when 3000 was meant —
 * each of those is a single edit, and each would destroy every executed agreement on the
 * instance in one command. Soft-deleting the envelope first makes that edit *reversible*
 * for `purge_grace_days`, and the audit event the first pass writes names every digest
 * scheduled to go, so an operator reading the trail during the grace window can see exactly
 * what is about to be destroyed and clear `deleted_at` if it is not what they meant.
 *
 * ## What selects an object
 *
 * A soft-deleted envelope row, soft-deleted for longer than the grace period. Nothing else:
 *
 *  - **Never prefix age.** docs/HANDOFF.md section 12 forbids garbage-collecting completed
 *    evidence by prefix age, and an artifact key contains the envelope's ULID rather than a
 *    date, so age of a prefix is not even available to be misused here.
 *  - **Never a soft-deleted `documents` row.** A document can be soft-deleted by an ordinary
 *    user action, and docs/BLOB_STORAGE.md is explicit that a soft delete is meant to be
 *    reversible — destroying the bytes would make it permanent. This class never reads
 *    `documents` at all; uploaded originals are only ever removed by the abandoned-draft
 *    pass, which excludes a soft-deleted row from its plan *and* re-checks the exclusion at
 *    the moment it deletes, so the rows and the bytes always go together or not at all
 *    ({@see RetentionSweeper}, and docs/security/review-2026-09.md finding B-3).
 *  - **Never a held envelope.** Re-checked here as well as in the sweep, because the grace
 *    period is long enough for a hold to arrive during it. That is the whole point of the
 *    grace period.
 *
 * What eligibility is *not* is a record that retention made the decision. It is inferred
 * from `deleted_at` alone, and nothing else in the application writes that column today —
 * `Envelope::$fillable` excludes it and no route or job deletes an envelope. So the safety
 * property the runbook rests on, that executed agreements are never destroyed until an
 * operator configures a reviewed window, lives in {@see RetentionSweeper} and not here: a
 * second writer of `envelopes.deleted_at` would inherit "destroy this agreement's bytes in
 * thirty days" without one. That is a known residual rather than an oversight
 * (docs/security/review-2026-09.md finding B-4); fixing it properly means a
 * `retention_scheduled_at` column, which is a schema change and not a docblock.
 *
 * ## What survives
 *
 * The `artifacts` rows. They refuse updates and deletes, so after a purge the row still
 * records the kind, the digest, the byte length, the seal key id, the seal certificate
 * digest, and the validation report of an agreement whose bytes are gone. That is
 * deliberate: retention destroys the document, not the evidence that there was one. It is
 * also why `esign:artifacts:verify` skips artifacts belonging to a soft-deleted envelope —
 * bytes removed on purpose are not an integrity failure.
 */
final readonly class BlobPurger
{
    public const PURGED = 'retention.artifact_blobs.purged';

    public function __construct(
        private ConnectionInterface $db,
        private ArtifactStore $store,
        private AuditRecorder $audit,
        private LegalHold $legalHold,
    ) {}

    /**
     * Envelopes whose bytes are eligible, with the objects that would go.
     *
     * @return list<array{envelope_id: int, envelope: string, deleted_at: string|null, held: bool, objects: list<array{artifact: string, kind: string, sha256: string, disk: string, path: string}>}>
     */
    public function plan(RetentionPolicy $policy, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $cutoff = $policy->purgeGraceCutoff($now);

        // Through the query builder, so the envelope soft-delete scope — which would hide
        // every row this pass is about — is not in the way.
        $envelopes = $this->db->table('envelopes')
            ->whereNotNull('deleted_at')
            ->where('deleted_at', '<', $cutoff)
            ->orderBy('id')
            ->get(['id', 'public_id', 'deleted_at', 'legal_hold_at']);

        if ($envelopes->isEmpty()) {
            return [];
        }

        $artifacts = $this->db->table('artifacts')
            ->whereIn('envelope_id', $envelopes->pluck('id')->all())
            ->orderBy('id')
            ->get(['envelope_id', 'public_id', 'kind', 'sha256', 'disk', 'path'])
            ->groupBy('envelope_id');

        $planned = [];

        foreach ($envelopes as $envelope) {
            $objects = [];

            foreach ($artifacts->get((int) $envelope->id, collect()) as $artifact) {
                $objects[] = [
                    'artifact' => (string) $artifact->public_id,
                    'kind' => (string) $artifact->kind,
                    'sha256' => (string) $artifact->sha256,
                    'disk' => (string) $artifact->disk,
                    'path' => (string) $artifact->path,
                ];
            }

            $planned[] = [
                'envelope_id' => (int) $envelope->id,
                'envelope' => (string) $envelope->public_id,
                'deleted_at' => $envelope->deleted_at === null ? null : (string) $envelope->deleted_at,
                'held' => $envelope->legal_hold_at !== null,
                'objects' => $objects,
            ];
        }

        return $planned;
    }

    /**
     * Remove the objects the plan named.
     *
     * @param  list<array{envelope_id: int, envelope: string, deleted_at: string|null, held: bool, objects: list<array{artifact: string, kind: string, sha256: string, disk: string, path: string}>}>  $planned
     * @return array{purged: int, already_gone: int, failed: int, held_skipped: int, envelopes: int}
     */
    public function purge(array $planned, AuditActor $actor): array
    {
        $purged = 0;
        $alreadyGone = 0;
        $failed = 0;
        $heldSkipped = 0;
        $envelopes = 0;

        foreach ($planned as $entry) {
            $envelope = Envelope::query()->withTrashed()->whereKey($entry['envelope_id'])->first();

            if ($envelope === null || $envelope->deleted_at === null) {
                // Restored during the grace window, which is exactly what the window is
                // for. Its bytes are not this command's business any more.
                continue;
            }

            if ($this->legalHold->isHeld($envelope)) {
                $heldSkipped++;

                continue;
            }

            $removed = [];

            foreach ($entry['objects'] as $object) {
                try {
                    if (! $this->store->exists($object['disk'], $object['path'])) {
                        $alreadyGone++;

                        continue;
                    }

                    $this->store->delete($object['disk'], $object['path']);
                    $purged++;
                    $removed[] = [
                        'artifact' => $object['artifact'],
                        'kind' => $object['kind'],
                        'sha256' => $object['sha256'],
                    ];
                } catch (ArtifactStorageException) {
                    // Left in place, counted, and reported. The row still names it, so a
                    // re-run will try again; nothing is lost by failing here.
                    $failed++;
                }
            }

            if ($removed !== []) {
                $envelopes++;

                $this->audit->record($actor, self::PURGED, $envelope, [
                    'envelope' => $entry['envelope'],
                    'soft_deleted_at' => $entry['deleted_at'],
                    // The digests whose bytes are now gone. The artifacts rows still hold
                    // these values and are immutable, so this event is a second, actor-
                    // attributed record of what was destroyed and when.
                    'purged_digests' => $removed,
                    'purged_count' => count($removed),
                ]);
            }
        }

        return [
            'purged' => $purged,
            'already_gone' => $alreadyGone,
            'failed' => $failed,
            'held_skipped' => $heldSkipped,
            'envelopes' => $envelopes,
        ];
    }
}
