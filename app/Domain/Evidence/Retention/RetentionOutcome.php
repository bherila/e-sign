<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Retention;

/**
 * What a retention sweep actually did, as distinct from what it planned to do.
 *
 * The two are reported separately because they can legitimately differ: a plan is computed,
 * printed, and then applied, and in between a legal hold can be placed or an envelope can
 * move. Every deletion re-checks its own predicates at the moment it runs, so a row that
 * changed underneath the plan is skipped rather than deleted, and `skipped` is how the
 * operator finds out.
 *
 * `objectsFailed` is not an error the sweep raises. A row is deleted before its bytes are,
 * so a failed object deletion leaves an unreferenced object rather than a row pointing at
 * nothing — recoverable by `esign:artifacts:prune-staging` for evidence keys and by a
 * re-run for document keys. Reversing the order would be the unrecoverable version.
 */
final readonly class RetentionOutcome
{
    /**
     * @param  list<string>  $skipped  Human-readable reasons, one per row the sweep declined
     *                                 to delete after the plan was made.
     */
    public function __construct(
        public int $authLogRowsDeleted,
        public int $abandonedEnvelopesDeleted,
        public int $unreferencedDocumentsDeleted,
        public int $objectsDeleted,
        public int $objectsFailed,
        public int $executedEnvelopesSoftDeleted,
        public array $skipped,
    ) {}
}
