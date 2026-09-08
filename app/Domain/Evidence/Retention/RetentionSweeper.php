<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Retention;

use App\Domain\Identity\Audit\AuditActor;
use App\Domain\Identity\Audit\AuditRecorder;
use App\Domain\Preparation\Documents\DocumentBlobStore;
use App\Domain\Preparation\Documents\DocumentStorageException;
use App\Domain\Signing\Envelopes\EnvelopeState;
use App\Domain\Signing\Models\Envelope;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use stdClass;

/**
 * The three retention policies, planned in one pass and applied in another.
 *
 * ## Why one class and not three commands
 *
 * The policies are separate (docs/HANDOFF.md section 12) but the *safety rules* are shared,
 * and duplicating them three times is how one of the copies ends up missing one:
 *
 *  1. **Nothing is selected by prefix age.** Every candidate is chosen by set membership
 *     against the rows that could reference it, exactly as docs/BLOB_STORAGE.md requires,
 *     and age is a second filter on that set. "Old" is never on its own a reason to delete.
 *  2. **A legal hold excludes an envelope from every pass**, and the exclusion is counted
 *     and reported. A sweep that quietly skipped held agreements would be indistinguishable
 *     from one that deleted them.
 *  3. **Reference queries read through the query builder**, never Eloquent. The soft-delete
 *     scope on `documents` and now on `envelopes` would hide a row that is still protecting
 *     its bytes and condemn them — the failure docs/BLOB_STORAGE.md calls out by name.
 *  4. **Rows are deleted before bytes.** The reverse order can leave a row pointing at an
 *     object that is not there, which is a document lost. This order can leave an object no
 *     row points at, which is a re-run away from tidy.
 *  5. **Every deletion re-checks its own predicates at the moment it runs.** The plan is
 *     printed to a human first, and a hold can arrive in between.
 *
 * ## The three passes
 *
 * **Authentication rows.** `auth_audit_log` belongs to auth-laravel and holds login,
 * passkey, and 2FA events. No attestation, digest, or artifact depends on one, which is why
 * this is the only pass with a finite default window.
 *
 * **Abandoned drafts.** An envelope in `draft` with a null `sent_at` went to nobody, so
 * there is nothing to prove about it. Alongside it, an uploaded document that neither an
 * envelope nor a template version references is a PDF sitting on a private disk for no
 * reason. Both are hard-deleted, rows and bytes: a draft is not evidence.
 *
 * A draft that somehow carries an attestation or a published artifact is skipped regardless
 * of its state. That combination should be impossible, and "impossible, therefore delete
 * it" is not a trade this class is willing to make.
 *
 * **Executed agreements.** Only when an operator has set `executed_documents_days`; the
 * shipped default is null and means never. Deletion is a soft delete of the envelope plus
 * an audit event naming every artifact digest scheduled to go, and the bytes are removed
 * later by {@see BlobPurger} once the grace period has passed. The `artifacts` rows are
 * never touched — the model refuses updates and deletes — so what survives is the record
 * that an agreement existed, its digests, and which seal material signed it. What is
 * destroyed is the document, not the evidence that there was one.
 */
final readonly class RetentionSweeper
{
    public const EXECUTED_DELETED = 'retention.executed_envelope.deleted';

    public const ABANDONED_DELETED = 'retention.abandoned_drafts.deleted';

    public const AUTH_LOGS_DELETED = 'retention.auth_logs.deleted';

    /**
     * Rows deleted per statement in the authentication-log pass.
     *
     * Chunked rather than one `DELETE`, because the first sweep on a deployment that has
     * been running for years can match millions of rows, and a single statement of that
     * size holds locks long enough to matter on MySQL and to time out on shared hosting.
     */
    private const AUTH_LOG_CHUNK = 1_000;

    public function __construct(
        private ConnectionInterface $db,
        private Repository $config,
        private AuditRecorder $audit,
        private LegalHold $legalHold,
        private DocumentBlobStore $documents,
    ) {}

    /**
     * What a sweep would delete, right now, under the configured policies.
     *
     * Read-only. Nothing here writes, and nothing here reads an object's bytes: the plan is
     * built entirely from rows.
     */
    public function plan(RetentionPolicy $policy, ?CarbonImmutable $now = null): RetentionPlan
    {
        $now ??= CarbonImmutable::now();
        $held = 0;

        [$authLogRows, $authLogTablePresent] = $this->planAuthLogs($policy, $now);
        $abandoned = $this->planAbandonedEnvelopes($policy, $now, $held);
        $documents = $this->planUnreferencedDocuments($policy, $now);
        $executed = $this->planExecutedEnvelopes($policy, $now, $held);

        return new RetentionPlan(
            authLogRows: $authLogRows,
            abandonedEnvelopes: $abandoned,
            unreferencedDocuments: $documents,
            executedEnvelopes: $executed,
            executedPolicyConfigured: $policy->deletesExecutedDocuments(),
            heldEnvelopesExcluded: $held,
            authLogTablePresent: $authLogTablePresent,
        );
    }

    /**
     * Delete exactly what the plan named, re-checking each row's predicates as it goes.
     */
    public function apply(
        RetentionPlan $plan,
        RetentionPolicy $policy,
        AuditActor $actor,
        ?CarbonImmutable $now = null,
    ): RetentionOutcome {
        $now ??= CarbonImmutable::now();
        $skipped = [];

        $authLogsDeleted = $this->applyAuthLogs($plan, $policy, $actor, $now);
        $abandonedEnvelopes = $this->applyAbandonedEnvelopes($plan, $skipped);
        [$documentsDeleted, $objectsDeleted, $objectsFailed] = $this->applyUnreferencedDocuments($plan, $skipped);
        $executed = $this->applyExecutedEnvelopes($plan, $actor, $skipped);

        if ($abandonedEnvelopes > 0 || $documentsDeleted > 0) {
            $this->audit->record($actor, self::ABANDONED_DELETED, null, [
                'envelopes' => $abandonedEnvelopes,
                'documents' => $documentsDeleted,
                'objects_deleted' => $objectsDeleted,
                'objects_missing_or_failed' => $objectsFailed,
                'swept_at' => $now->toIso8601String(),
            ]);
        }

        return new RetentionOutcome(
            authLogRowsDeleted: $authLogsDeleted,
            abandonedEnvelopesDeleted: $abandonedEnvelopes,
            unreferencedDocumentsDeleted: $documentsDeleted,
            objectsDeleted: $objectsDeleted,
            objectsFailed: $objectsFailed,
            executedEnvelopesSoftDeleted: $executed,
            skipped: $skipped,
        );
    }

    /** @return array{0: int, 1: bool} Row count, and whether the table is installed at all. */
    private function planAuthLogs(RetentionPolicy $policy, CarbonImmutable $now): array
    {
        $table = $this->authLogTable();

        if (! $this->db->getSchemaBuilder()->hasTable($table)) {
            return [0, false];
        }

        $count = $this->db->table($table)
            ->where('created_at', '<', $policy->authLogsCutoff($now))
            ->count();

        return [$count, true];
    }

    /**
     * @param  int  $held  Incremented by the number of held envelopes this pass excluded.
     * @return list<array{id: int, public_id: string, workspace_id: int, title: string, created_at: string|null}>
     */
    private function planAbandonedEnvelopes(RetentionPolicy $policy, CarbonImmutable $now, int &$held): array
    {
        $cutoff = $policy->abandonedDraftsCutoff($now);

        $held += $this->abandonedCandidates($cutoff)
            ->whereNotNull('legal_hold_at')
            ->count();

        $rows = $this->abandonedCandidates($cutoff)
            ->whereNull('legal_hold_at')
            ->orderBy('id')
            ->get(['id', 'public_id', 'workspace_id', 'title', 'created_at']);

        return $rows->map(static fn (stdClass $row): array => [
            'id' => (int) $row->id,
            'public_id' => (string) $row->public_id,
            'workspace_id' => (int) $row->workspace_id,
            'title' => (string) $row->title,
            'created_at' => $row->created_at === null ? null : (string) $row->created_at,
        ])->all();
    }

    /**
     * Drafts old enough to sweep, with the two impossible-but-checked exclusions.
     *
     * Through the query builder, so the envelope soft-delete scope cannot hide an
     * already-swept row and make it look sweepable a second time.
     *
     * @return Builder
     */
    private function abandonedCandidates(CarbonImmutable $cutoff)
    {
        return $this->db->table('envelopes')
            ->where('state', EnvelopeState::Draft->value)
            ->whereNull('sent_at')
            ->whereNull('deleted_at')
            ->where('created_at', '<', $cutoff)
            // A draft cannot have either. If one does, something is wrong in a way that
            // deleting the row would destroy the evidence of.
            ->whereNotExists(fn ($query) => $query->from('recipient_attestations')
                ->whereColumn('recipient_attestations.envelope_id', 'envelopes.id'))
            ->whereNotExists(fn ($query) => $query->from('artifacts')
                ->whereColumn('artifacts.envelope_id', 'envelopes.id'));
    }

    /**
     * @return list<array{id: int, public_id: string, title: string, objects: list<array{disk: string, path: string}>}>
     */
    private function planUnreferencedDocuments(RetentionPolicy $policy, CarbonImmutable $now): array
    {
        $cutoff = $policy->abandonedDraftsCutoff($now);

        // Every reference, read without a single Eloquent scope in the way. `envelopes` is
        // read including soft-deleted rows on purpose: retention soft-deletes an envelope
        // and removes its bytes only after the grace period, so a soft-deleted envelope is
        // still protecting the revision it was built from.
        $referenced = $this->db->table('envelopes')
            ->whereNotNull('document_revision_id')
            ->pluck('document_revision_id')
            ->merge($this->db->table('template_versions')->pluck('document_revision_id'))
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->all();

        $candidates = $this->db->table('documents')
            ->where('created_at', '<', $cutoff)
            ->whereNotExists(function ($query) use ($referenced): void {
                $query->from('document_revisions')
                    ->whereColumn('document_revisions.document_id', 'documents.id');

                if ($referenced !== []) {
                    $query->whereIn('document_revisions.id', $referenced);
                } else {
                    $query->whereRaw('1 = 0');
                }
            })
            ->orderBy('id')
            ->get(['id', 'public_id', 'title', 'original_disk', 'original_path']);

        if ($candidates->isEmpty()) {
            return [];
        }

        $revisions = $this->db->table('document_revisions')
            ->whereIn('document_id', $candidates->pluck('id')->all())
            ->get(['document_id', 'disk', 'path'])
            ->groupBy('document_id');

        return $candidates->map(function (stdClass $document) use ($revisions): array {
            $objects = [[
                'disk' => (string) $document->original_disk,
                'path' => (string) $document->original_path,
            ]];

            foreach ($revisions->get((int) $document->id, collect()) as $revision) {
                $objects[] = ['disk' => (string) $revision->disk, 'path' => (string) $revision->path];
            }

            $unique = [];

            foreach ($objects as $object) {
                $unique[$object['disk'].':'.$object['path']] = $object;
            }

            return [
                'id' => (int) $document->id,
                'public_id' => (string) $document->public_id,
                'title' => (string) $document->title,
                'objects' => array_values($unique),
            ];
        })->all();
    }

    /**
     * @param  int  $held  Incremented by the number of held envelopes this pass excluded.
     * @return list<array{id: int, public_id: string, workspace_id: int, title: string, completed_at: string|null, artifacts: list<array{public_id: string, kind: string, sha256: string, bytes: int}>}>
     */
    private function planExecutedEnvelopes(RetentionPolicy $policy, CarbonImmutable $now, int &$held): array
    {
        $cutoff = $policy->executedDocumentsCutoff($now);

        // The shipped default. No operator has reviewed a window, so there is no window,
        // and this pass does not exist.
        if ($cutoff === null) {
            return [];
        }

        $held += $this->executedCandidates($cutoff)->whereNotNull('legal_hold_at')->count();

        $rows = $this->executedCandidates($cutoff)
            ->whereNull('legal_hold_at')
            ->orderBy('id')
            ->get(['id', 'public_id', 'workspace_id', 'title', 'completed_at']);

        if ($rows->isEmpty()) {
            return [];
        }

        $artifacts = $this->db->table('artifacts')
            ->whereIn('envelope_id', $rows->pluck('id')->all())
            ->orderBy('id')
            ->get(['envelope_id', 'public_id', 'kind', 'sha256', 'bytes'])
            ->groupBy('envelope_id');

        return $rows->map(static function (stdClass $row) use ($artifacts): array {
            $digests = [];

            foreach ($artifacts->get((int) $row->id, collect()) as $artifact) {
                $digests[] = [
                    'public_id' => (string) $artifact->public_id,
                    'kind' => (string) $artifact->kind,
                    'sha256' => (string) $artifact->sha256,
                    'bytes' => (int) $artifact->bytes,
                ];
            }

            return [
                'id' => (int) $row->id,
                'public_id' => (string) $row->public_id,
                'workspace_id' => (int) $row->workspace_id,
                'title' => (string) $row->title,
                'completed_at' => $row->completed_at === null ? null : (string) $row->completed_at,
                'artifacts' => $digests,
            ];
        })->all();
    }

    /**
     * @return Builder
     */
    private function executedCandidates(CarbonImmutable $cutoff)
    {
        return $this->db->table('envelopes')
            ->where('state', EnvelopeState::Completed->value)
            ->whereNull('deleted_at')
            ->whereNotNull('completed_at')
            ->where('completed_at', '<', $cutoff);
    }

    private function applyAuthLogs(
        RetentionPlan $plan,
        RetentionPolicy $policy,
        AuditActor $actor,
        CarbonImmutable $now,
    ): int {
        if (! $plan->authLogTablePresent || $plan->authLogRows === 0) {
            return 0;
        }

        $table = $this->authLogTable();
        $cutoff = $policy->authLogsCutoff($now);
        $deleted = 0;

        do {
            $batch = $this->db->table($table)
                ->where('created_at', '<', $cutoff)
                ->limit(self::AUTH_LOG_CHUNK)
                ->pluck('id')
                ->all();

            if ($batch === []) {
                break;
            }

            $deleted += $this->db->table($table)->whereIn('id', $batch)->delete();
        } while (count($batch) === self::AUTH_LOG_CHUNK);

        if ($deleted > 0) {
            $this->audit->record($actor, self::AUTH_LOGS_DELETED, null, [
                'rows' => $deleted,
                'older_than' => $cutoff->toIso8601String(),
                'retained_days' => $policy->authLogsDays,
            ]);
        }

        return $deleted;
    }

    /**
     * @param  list<string>  $skipped
     */
    private function applyAbandonedEnvelopes(RetentionPlan $plan, array &$skipped): int
    {
        $deleted = 0;

        foreach ($plan->abandonedEnvelopes as $planned) {
            $envelope = Envelope::query()->whereKey($planned['id'])->first();

            if ($envelope === null) {
                continue;
            }

            if ($this->legalHold->isHeld($envelope)) {
                $skipped[] = sprintf(
                    'draft %s: a legal hold was placed after the plan was made',
                    $planned['public_id'],
                );

                continue;
            }

            if ($envelope->state !== EnvelopeState::Draft || $envelope->sent_at !== null) {
                $skipped[] = sprintf(
                    'draft %s: it left the draft state after the plan was made',
                    $planned['public_id'],
                );

                continue;
            }

            $this->db->transaction(function () use ($planned): void {
                $id = $planned['id'];

                // Values first, then the rows they point at. Invitations, sessions, OTP
                // challenges, and attestations cannot exist for a never-sent draft; they are
                // deleted anyway so that one surprising row cannot abort the whole sweep
                // with a foreign-key error two tables later.
                $this->db->table('envelope_field_values')->where('envelope_id', $id)->delete();
                $this->db->table('signing_otp_challenges')->where('envelope_id', $id)->delete();
                $this->db->table('signing_sessions')->where('envelope_id', $id)->delete();
                $this->db->table('recipient_invitations')->where('envelope_id', $id)->delete();
                $this->db->table('envelope_recipients')->where('envelope_id', $id)->delete();
                $this->db->table('envelopes')->where('id', $id)->delete();
            });

            $deleted++;
        }

        return $deleted;
    }

    /**
     * @param  list<string>  $skipped
     * @return array{0: int, 1: int, 2: int} Documents deleted, objects deleted, objects not removed.
     */
    private function applyUnreferencedDocuments(RetentionPlan $plan, array &$skipped): array
    {
        $documents = 0;
        $objectsDeleted = 0;
        $objectsFailed = 0;

        foreach ($plan->unreferencedDocuments as $planned) {
            $stillUnreferenced = $this->db->table('document_revisions')
                ->where('document_id', $planned['id'])
                ->whereExists(fn ($query) => $query->from('envelopes')
                    ->whereColumn('envelopes.document_revision_id', 'document_revisions.id'))
                ->doesntExist()
                && $this->db->table('document_revisions')
                    ->where('document_id', $planned['id'])
                    ->whereExists(fn ($query) => $query->from('template_versions')
                        ->whereColumn('template_versions.document_revision_id', 'document_revisions.id'))
                    ->doesntExist();

            if (! $stillUnreferenced) {
                $skipped[] = sprintf(
                    'document %s: an envelope or template version started referencing it after the '
                    .'plan was made',
                    $planned['public_id'],
                );

                continue;
            }

            // Rows first, in one transaction. `document_revisions` refuses deletion through
            // its own model, which is correct for every other caller; retention is the one
            // reviewed path that may remove them, and it goes through the query builder
            // having already proved nothing references them.
            $this->db->transaction(function () use ($planned): void {
                $this->db->table('document_revisions')->where('document_id', $planned['id'])->delete();
                $this->db->table('documents')->where('id', $planned['id'])->delete();
            });

            $documents++;

            foreach ($planned['objects'] as $object) {
                try {
                    if ($this->documents->delete($object['disk'], $object['path'])) {
                        $objectsDeleted++;
                    } else {
                        $objectsFailed++;
                    }
                } catch (DocumentStorageException) {
                    // The rows are already gone, so nothing points at these bytes. They are
                    // an orphan for a later sweep rather than a lost document, which is
                    // precisely why the rows go first.
                    $objectsFailed++;
                }
            }
        }

        return [$documents, $objectsDeleted, $objectsFailed];
    }

    /**
     * @param  list<string>  $skipped
     */
    private function applyExecutedEnvelopes(RetentionPlan $plan, AuditActor $actor, array &$skipped): int
    {
        if (! $plan->executedPolicyConfigured) {
            return 0;
        }

        $deleted = 0;

        foreach ($plan->executedEnvelopes as $planned) {
            $envelope = Envelope::query()->whereKey($planned['id'])->first();

            if ($envelope === null) {
                continue;
            }

            if ($this->legalHold->isHeld($envelope)) {
                $skipped[] = sprintf(
                    'executed envelope %s: a legal hold was placed after the plan was made',
                    $planned['public_id'],
                );

                continue;
            }

            if ($envelope->state !== EnvelopeState::Completed) {
                $skipped[] = sprintf(
                    'executed envelope %s: it is no longer completed',
                    $planned['public_id'],
                );

                continue;
            }

            // The audit event and the soft delete commit together. Either the trail says an
            // agreement was scheduled for destruction and it was, or neither happened.
            $this->db->transaction(function () use ($envelope, $planned, $actor): void {
                $this->audit->record($actor, self::EXECUTED_DELETED, $envelope, [
                    'envelope' => $planned['public_id'],
                    'workspace_id' => $planned['workspace_id'],
                    'title' => $planned['title'],
                    'completed_at' => $planned['completed_at'],
                    // Every digest that is scheduled to be destroyed, named here because
                    // after the blob purge this event is the only place they exist outside
                    // the immutable `artifacts` rows.
                    'artifact_digests' => $planned['artifacts'],
                    'artifact_count' => count($planned['artifacts']),
                    'blobs_purged_after_grace' => true,
                ]);

                $envelope->delete();
            });

            $deleted++;
        }

        return $deleted;
    }

    private function authLogTable(): string
    {
        $table = $this->config->get('bherila-auth.audit.table', 'auth_audit_log');

        return is_string($table) && $table !== '' ? $table : 'auth_audit_log';
    }
}
