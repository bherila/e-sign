<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Retention;

/**
 * Exactly what a retention sweep would delete, computed before anything is deleted.
 *
 * The plan exists so that `--dry-run` and a real run are the *same* computation rather than
 * two code paths that are supposed to agree. `esign:retention:run` builds a plan, prints
 * it, and then either stops or hands the identical object to
 * {@see RetentionSweeper::apply()}. A dry run that reported one thing while the real run
 * did another would be worse than no dry run at all, and that failure mode is only ruled
 * out by there being one planner.
 *
 * Everything here is row identifiers, counts, and digests. No document bytes, no artifact
 * bytes, and no storage key: the plan is printed to a terminal and, for executed evidence,
 * copied into an audit event.
 *
 * @phpstan-type PlannedDocument array{id: int, public_id: string, title: string, objects: list<array{disk: string, path: string}>}
 * @phpstan-type PlannedEnvelope array{id: int, public_id: string, workspace_id: int, title: string, created_at: string|null}
 * @phpstan-type PlannedExecuted array{id: int, public_id: string, workspace_id: int, title: string, completed_at: string|null, artifacts: list<array{public_id: string, kind: string, sha256: string, bytes: int}>}
 */
final readonly class RetentionPlan
{
    /**
     * @param  int  $authLogRows  Rows in the authentication audit log older than the window.
     * @param  list<PlannedEnvelope>  $abandonedEnvelopes  Drafts that were never sent.
     * @param  list<PlannedDocument>  $unreferencedDocuments  Uploads no envelope or template names.
     * @param  list<PlannedExecuted>  $executedEnvelopes  Completed envelopes past the reviewed window.
     * @param  bool  $executedPolicyConfigured  False when `executed_documents_days` is unset,
     *                                          which is the shipped default and the reason
     *                                          `executedEnvelopes` is then always empty.
     * @param  int  $heldEnvelopesExcluded  Envelopes that would otherwise have been selected
     *                                      and were left alone because of a legal hold. Reported
     *                                      rather than silently dropped: a sweep that quietly
     *                                      passed over held agreements looks exactly like one
     *                                      that deleted them.
     * @param  bool  $authLogTablePresent  False when auth-laravel's table is not installed.
     */
    public function __construct(
        public int $authLogRows,
        public array $abandonedEnvelopes,
        public array $unreferencedDocuments,
        public array $executedEnvelopes,
        public bool $executedPolicyConfigured,
        public int $heldEnvelopesExcluded,
        public bool $authLogTablePresent,
    ) {}

    public function isEmpty(): bool
    {
        return $this->authLogRows === 0
            && $this->abandonedEnvelopes === []
            && $this->unreferencedDocuments === []
            && $this->executedEnvelopes === [];
    }

    /**
     * Every object the abandoned-draft pass would remove, deduplicated by disk and path.
     *
     * One set of bytes can be named by more than one row — a document whose review revision
     * was not rebuilt shares its key with the original, because both are content-addressed
     * over the same bytes — so the same object must not be handed to the storage adapter
     * twice.
     *
     * @return list<array{disk: string, path: string}>
     */
    public function abandonedObjects(): array
    {
        $objects = [];

        foreach ($this->unreferencedDocuments as $document) {
            foreach ($document['objects'] as $object) {
                $objects[$object['disk'].':'.$object['path']] = $object;
            }
        }

        return array_values($objects);
    }
}
