<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Retention\Console;

use App\Domain\Evidence\Retention\Exceptions\LegalHoldActive;
use App\Domain\Evidence\Retention\Exceptions\RetentionRefused;
use App\Domain\Evidence\Retention\RecipientEraser;
use App\Domain\Identity\Audit\AuditActor;
use App\Domain\Signing\Models\EnvelopeRecipient;
use Illuminate\Console\Command;

/**
 * Replaces one recipient's contact details with tombstones and leaves the agreement intact.
 *
 * The distinction the command exists to enforce is spelled out in {@see RecipientEraser} and
 * in docs/operations/retention.md, and it is worth repeating at the surface an operator
 * actually types into: **this erases contact data, not signatures.** The attestation chain,
 * the field values, the digests, and the sealed executed PDF are the instrument the person
 * entered into, not the service's record about them, and both parties depend on it staying
 * what it was. What goes is the mailbox, the display name, the identity snapshot, and the
 * addressing on the transactional messages.
 *
 * It refuses on a held envelope, exactly as every deletion path does.
 */
final class EraseRecipientCommand extends Command
{
    protected $signature = 'esign:privacy:erase-recipient
        {recipient : The recipient public id}
        {--reason= : The basis for the erasure. Required and recorded.}';

    protected $description = "Tombstone a recipient's contact details, keeping their attestations and the executed artifact";

    public function handle(RecipientEraser $eraser): int
    {
        $publicId = (string) $this->argument('recipient');

        $recipient = EnvelopeRecipient::query()->where('public_id', $publicId)->first();

        if ($recipient === null) {
            $this->error('No recipient with public id '.$publicId.'.');

            return self::FAILURE;
        }

        try {
            $result = $eraser->erase($recipient, (string) $this->option('reason'), AuditActor::console('esign:privacy:erase-recipient'));
        } catch (LegalHoldActive $held) {
            $this->error($held->getMessage());

            return self::FAILURE;
        } catch (RetentionRefused $refusal) {
            $this->error($refusal->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Recipient %s on envelope %s has been erased. Their address is now %s.',
            $result['recipient'],
            $result['envelope'],
            $result['tombstone_email'],
        ));

        $this->line('Rewritten: '.implode(', ', $result['columns']));
        $this->line(sprintf('Transactional message rows tombstoned: %d.', $result['outbound_mail_rows_erased']));

        $this->newLine();
        $this->comment(
            'Kept, deliberately: the attestation chain, the stored field values including the captured '
            .'signature, and the published executed PDF, completion report, and evidence document. Those '
            .'are the agreement rather than the service\'s record about a person, the other party relies '
            .'on them, and every digest in evidence.json describes those exact bytes.'
        );

        return self::SUCCESS;
    }
}
