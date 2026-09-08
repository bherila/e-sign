<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Retention\Console;

use App\Domain\Evidence\Retention\RetentionPlan;
use App\Domain\Evidence\Retention\RetentionPolicy;
use App\Domain\Evidence\Retention\RetentionSweeper;
use App\Domain\Identity\Audit\AuditActor;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Contracts\Config\Repository;

/**
 * Applies the three retention policies.
 *
 * ## What it prints, and why the two modes share one computation
 *
 * `--dry-run` prints the plan and stops. Without it, the command prints the *same* plan,
 * from the same {@see RetentionSweeper::plan()} call, and then applies that object. There is
 * no separate "what would happen" code path, because a dry run that disagreed with the real
 * run would be worse than having no dry run at all — it would be a rehearsal that certifies
 * the wrong thing.
 *
 * ## Confirmation
 *
 * `ConfirmableTrait` asks before proceeding when `APP_ENV=production`, and `--force` skips
 * the question for the scheduler. docs/BLOB_STORAGE.md rule 4 argues for `--apply` polarity
 * instead, and `esign:artifacts:prune-staging` follows it; this command is the deliberate
 * exception because its safe mode is not "do nothing" but "do only what the three policies
 * say", and on a default deployment those policies never touch an executed document at all.
 * The interactive confirmation is what replaces the missing `--apply`.
 *
 * ## What it never does
 *
 * It never deletes an executed document unless `esign.retention.executed_documents_days` has
 * been set by an operator, and it never touches an envelope under legal hold. Both are
 * reported explicitly rather than left to be inferred from a count of zero: "nothing was
 * deleted because nothing was old enough" and "nothing was deleted because the policy is
 * off" are different facts, and an operator who cannot tell them apart does not know whether
 * their retention policy works.
 */
final class RunRetentionCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'esign:retention:run
        {--dry-run : Report what would be deleted and stop}
        {--force : Skip the confirmation prompt in production}';

    protected $description = 'Apply the authentication-log, abandoned-draft, and executed-document retention policies';

    public function handle(RetentionSweeper $sweeper, Repository $config): int
    {
        $policy = RetentionPolicy::fromConfig($config);
        $plan = $sweeper->plan($policy);

        $this->reportPolicies($policy);
        $this->reportPlan($plan);

        if ($this->option('dry-run')) {
            $this->comment('Dry run. Nothing was deleted. Re-run without --dry-run to apply exactly the above.');

            return self::SUCCESS;
        }

        if ($plan->isEmpty()) {
            $this->info('Nothing to delete.');

            return self::SUCCESS;
        }

        if (! $this->confirmToProceed('Applying retention deletes the rows and objects listed above')) {
            return self::FAILURE;
        }

        $outcome = $sweeper->apply($plan, $policy, AuditActor::console('esign:retention:run'));

        $this->newLine();
        $this->info(sprintf(
            'Deleted %d authentication log row(s), %d abandoned draft(s), and %d unreferenced document(s) '
            .'(%d object(s) removed, %d already gone or unreachable).',
            $outcome->authLogRowsDeleted,
            $outcome->abandonedEnvelopesDeleted,
            $outcome->unreferencedDocumentsDeleted,
            $outcome->objectsDeleted,
            $outcome->objectsFailed,
        ));

        if ($outcome->executedEnvelopesSoftDeleted > 0) {
            $this->info(sprintf(
                'Soft-deleted %d executed envelope(s). Their artifact digests are recorded in the audit '
                .'trail and their bytes remain until `esign:retention:purge-blobs` runs after the '
                .'%d-day grace period.',
                $outcome->executedEnvelopesSoftDeleted,
                $policy->purgeGraceDays,
            ));
        }

        foreach ($outcome->skipped as $skip) {
            $this->warn('skipped '.$skip);
        }

        return self::SUCCESS;
    }

    private function reportPolicies(RetentionPolicy $policy): void
    {
        $this->line(sprintf('Authentication log rows are kept for %d day(s).', $policy->authLogsDays));
        $this->line(sprintf(
            'Drafts that were never sent, and documents no envelope or template references, are kept for %d day(s).',
            $policy->abandonedDraftsDays,
        ));

        if ($policy->deletesExecutedDocuments()) {
            $this->line(sprintf(
                'Executed documents are deleted %d day(s) after completion, under the policy an operator '
                .'configured.',
                $policy->executedDocumentsDays,
            ));
        } else {
            $this->line(
                'Executed documents are never deleted automatically: esign.retention.executed_documents_days '
                .'is not set. That is the shipped default, and it stays that way until an operator sets a '
                .'reviewed value.'
            );
        }

        $this->newLine();
    }

    private function reportPlan(RetentionPlan $plan): void
    {
        if (! $plan->authLogTablePresent) {
            $this->warn('The authentication audit table is not installed; that policy has nothing to sweep.');
        } else {
            $this->line(sprintf('%d authentication log row(s) are older than the window.', $plan->authLogRows));
        }

        $this->line(sprintf(
            '%d abandoned draft(s) and %d unreferenced document(s) (%d storage object(s)).',
            count($plan->abandonedEnvelopes),
            count($plan->unreferencedDocuments),
            count($plan->abandonedObjects()),
        ));

        foreach ($plan->abandonedEnvelopes as $envelope) {
            $this->line('  draft '.$envelope['public_id'].' created '.($envelope['created_at'] ?? 'unknown'));
        }

        foreach ($plan->unreferencedDocuments as $document) {
            $this->line(sprintf(
                '  document %s (%d object(s))',
                $document['public_id'],
                count($document['objects']),
            ));
        }

        if ($plan->executedPolicyConfigured) {
            $this->line(sprintf('%d executed envelope(s) are past the reviewed window.', count($plan->executedEnvelopes)));

            foreach ($plan->executedEnvelopes as $envelope) {
                $this->line(sprintf(
                    '  envelope %s completed %s, %d artifact(s): %s',
                    $envelope['public_id'],
                    $envelope['completed_at'] ?? 'unknown',
                    count($envelope['artifacts']),
                    implode(', ', array_map(
                        static fn (array $artifact): string => $artifact['kind'].' '.substr($artifact['sha256'], 0, 12),
                        $envelope['artifacts'],
                    )) ?: 'none recorded',
                ));
            }
        }

        if ($plan->heldEnvelopesExcluded > 0) {
            $this->warn(sprintf(
                '%d envelope(s) matched a policy and were excluded because they are under legal hold.',
                $plan->heldEnvelopesExcluded,
            ));
        }

        $this->newLine();
    }
}
