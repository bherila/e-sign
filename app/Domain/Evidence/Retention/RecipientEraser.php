<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Retention;

use App\Domain\Evidence\Retention\Exceptions\LegalHoldActive;
use App\Domain\Evidence\Retention\Exceptions\RetentionRefused;
use App\Domain\Identity\Audit\AuditActor;
use App\Domain\Identity\Audit\AuditRecorder;
use App\Domain\Signing\Envelopes\EnvelopeState;
use App\Domain\Signing\Models\EnvelopeRecipient;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Replaces one recipient's contact details with tombstones, and leaves the agreement alone.
 *
 * ## The distinction this class exists to make
 *
 * A signed agreement contains two different things that look alike. One is **contact data**:
 * the mailbox an invitation was sent to, the display name on a reminder, the identity
 * details captured to decide who was allowed in. That is personal data the service holds in
 * order to operate, it has no evidential role once the agreement is executed, and it is
 * what an erasure request is about.
 *
 * The other is **the instrument**: the attestation chain, the digests it binds, the field
 * values that were drawn on the document, and the sealed executed PDF. Those are not the
 * service's record *about* a person; they are the agreement that person entered into, and
 * both parties — not just the one asking — depend on it remaining what it was. Erasing an
 * attestation would not remove personal data from an executed contract; it would break the
 * chain that proves the contract was agreed, while leaving the contract itself in force.
 *
 * So this class rewrites the first and refuses to touch the second, and says so out loud
 * both here and in docs/operations/retention.md, because "we erased the signature" is a
 * sentence somebody will eventually ask for.
 *
 * ## What is rewritten
 *
 * | Row | Column | Becomes |
 * |---|---|---|
 * | `envelope_recipients` | `name` | `Erased recipient` |
 * | `envelope_recipients` | `email` | a per-recipient tombstone address in `.invalid` |
 * | `envelope_recipients` | `identity_snapshot` | a marker recording that it was erased |
 * | `outbound_mails` | `to_email`, `to_name`, `subject` | tombstoned per message |
 * | `outbound_mails` | `context.recipient_name`, `context.actor_name` | tombstoned per message |
 *
 * The tombstone address is derived from the recipient's public ULID and the reserved
 * `.invalid` TLD (RFC 2606), so it stays unique — `envelope_recipients` has a real uniqueness
 * expectation per envelope and two erasures on one envelope must not collide — while being
 * unroutable by construction. A blank email would be indistinguishable from a bug.
 *
 * ## What is not rewritten, and why
 *
 * - **Attestations.** Immutable and chained: each one includes the digest of the previous
 *   acceptance, so altering any field of one invalidates every later link. They already
 *   contain no contact details — an attestation binds recipient *ids*, digests, a consent
 *   version, a session reference, and hashed client evidence.
 * - **Field values, including the signature image.** These are content of the agreement.
 *   `material_values_sha256` covers the shared ones and the executed PDF was rendered from
 *   all of them; changing one would leave every digest in `evidence.json` describing bytes
 *   that no longer exist.
 * - **The executed PDF, the completion report, and the evidence document.** Published,
 *   content-addressed, and immutable. The name on the signature line is part of the
 *   instrument.
 * - **Anything on a held envelope.** A legal hold overrides erasure exactly as it overrides
 *   deletion; the request is refused rather than partially applied.
 *
 * The audit event records which columns were rewritten and how many message rows were
 * touched, and never records the values that were erased — an erasure whose audit trail
 * quotes the erased address has not erased anything.
 */
final readonly class RecipientEraser
{
    public const ERASED = 'retention.recipient.erased';

    public const NAME_TOMBSTONE = 'Erased recipient';

    public const SUBJECT_TOMBSTONE = 'Subject erased at the recipient\'s request';

    public function __construct(
        private ConnectionInterface $db,
        private AuditRecorder $audit,
        private LegalHold $legalHold,
    ) {}

    /**
     * A unique, unroutable address for one recipient.
     *
     * `.invalid` is reserved by RFC 2606 and can never resolve, and the local part is the
     * recipient's own public identifier, so the value is stable across re-runs and cannot
     * collide with another erasure on the same envelope.
     */
    public static function tombstoneEmail(string $recipientPublicId): string
    {
        return 'erased-'.strtolower($recipientPublicId).'@erased.invalid';
    }

    /**
     * @param  string  $reason  Why the erasure was requested. Stored on the audit event; a
     *                          privacy action with no recorded basis is one nobody can
     *                          later justify.
     * @return array{recipient: string, envelope: string, tombstone_email: string, columns: list<string>, outbound_mail_rows_erased: int}
     *
     * @throws LegalHoldActive When the envelope is held.
     * @throws RetentionRefused When the reason is empty, or the recipient is already erased.
     */
    /**
     * Envelope states in which a recipient's contact data has stopped doing any work.
     *
     * `draft`, `sent`, `in_progress`, and `finalizing` are all excluded: in each of them the
     * address is still how the agreement reaches the person, or is still being read into an
     * artifact that has not been produced yet.
     */
    public const ERASABLE_STATES = [
        EnvelopeState::Completed,
        EnvelopeState::Cancelled,
        EnvelopeState::Declined,
        EnvelopeState::Expired,
        EnvelopeState::FinalizationFailed,
    ];

    public function erase(EnvelopeRecipient $recipient, string $reason, AuditActor $actor): array
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new RetentionRefused(
                'An erasure needs a reason. It is an irreversible change to a signed agreement\'s '
                .'surrounding records, and the trail has to say on whose request it was made.',
            );
        }

        // Including a soft-deleted envelope: retention may already have scheduled the
        // agreement for destruction, and an erasure request that arrives during the grace
        // window still has to find its envelope to check the hold.
        $envelope = $recipient->envelope()->withTrashed()->first();

        if ($envelope === null) {
            throw new RetentionRefused(sprintf(
                'Recipient %s has no envelope. Refusing to erase a row whose agreement cannot be '
                .'located, because the legal-hold check cannot be made.',
                $recipient->public_id,
            ));
        }

        // A hold overrides erasure exactly as it overrides deletion.
        $this->legalHold->assertNotHeld($envelope);

        // The whole justification for this operation — contact data has no evidential role
        // *once the agreement is executed* — is conditional on a condition that was never
        // tested (docs/security/review-2026-09.md finding B-7). Erasing a live envelope's
        // recipient rewrites the address invitations and codes are sent to, so the signer
        // becomes unreachable and the agreement hangs until expiry; and if the other parties
        // finish, `FinalizationInput` reads the tombstone at render time and the sealed
        // executed PDF names that party as "Erased recipient" under the service seal,
        // permanently.
        if (! in_array($envelope->state, self::ERASABLE_STATES, true)) {
            throw new RetentionRefused(sprintf(
                'Envelope %s is %s. A recipient can only be erased once the agreement has reached a '
                .'terminal state (%s): erasing a live one makes the signer unreachable and can seal '
                .'the tombstone into the executed document. Cancel or complete it first.',
                $envelope->public_id,
                $envelope->state->value,
                implode(', ', array_map(static fn (EnvelopeState $s): string => $s->value, self::ERASABLE_STATES)),
            ));
        }

        $tombstone = self::tombstoneEmail($recipient->public_id);

        if ($recipient->email === $tombstone && $recipient->name === self::NAME_TOMBSTONE) {
            throw new RetentionRefused(sprintf(
                'Recipient %s has already been erased. Re-running would add a second audit event '
                .'implying a second request.',
                $recipient->public_id,
            ));
        }

        $erasedAt = CarbonImmutable::now();
        $columns = ['envelope_recipients.name', 'envelope_recipients.email', 'envelope_recipients.identity_snapshot'];

        $mails = $this->db->transaction(function () use ($recipient, $tombstone, $erasedAt, $actor, $reason, $envelope, $columns): int {
            $recipient->name = self::NAME_TOMBSTONE;
            $recipient->email = $tombstone;
            $recipient->identity_snapshot = [
                'erased' => true,
                'erased_at' => $erasedAt->toIso8601String(),
            ];
            $recipient->save();

            $mails = $this->eraseOutboundMail($recipient, $tombstone, $erasedAt);

            $this->audit->record($actor, self::ERASED, $recipient, [
                'recipient' => $recipient->public_id,
                'envelope' => $envelope->public_id,
                'workspace_id' => $envelope->workspace_id,
                'reason' => $reason,
                'erased_at' => $erasedAt->toIso8601String(),
                // What was rewritten, never what it used to say.
                'erased_columns' => $columns,
                'outbound_mail_rows_erased' => $mails,
                // Stated on the record, so the reason signatures survive is part of the
                // trail rather than only part of the documentation.
                'retained' => [
                    'recipient_attestations' => 'immutable and chained; they bind recipient ids and '
                        .'digests, not contact details, and the executed agreement rests on them',
                    'envelope_field_values' => 'content of the agreement, covered by the published digests',
                    'artifacts' => 'the executed PDF, completion report, and evidence document are the '
                        .'instrument and are never rewritten',
                ],
            ]);

            return $mails;
        });

        return [
            'recipient' => $recipient->public_id,
            'envelope' => $envelope->public_id,
            'tombstone_email' => $tombstone,
            'columns' => $columns,
            'outbound_mail_rows_erased' => $mails,
        ];
    }

    /**
     * Tombstone the transactional messages addressed to this person.
     *
     * The outbox rows are the service's own record of having contacted somebody, and they
     * carry the address, the display name, the rendered subject, and a context blob that
     * includes both. They are *not* evidence about the agreement — `sent_to_provider` proves
     * a transport accepted bytes, nothing more — so the contact data in them is erasable
     * while the row itself, its state history, and its timestamps stay, and the fact that a
     * message was sent survives.
     *
     * Matched on `related_type`/`related_id`, which is how `MailOutbox` records what a
     * message was about, rather than on the address: matching on the address would sweep up
     * a different recipient who happens to share a mailbox.
     */
    private function eraseOutboundMail(EnvelopeRecipient $recipient, string $tombstone, CarbonImmutable $erasedAt): int
    {
        $rows = $this->db->table('outbound_mails')
            ->where('related_type', $recipient->getMorphClass())
            ->where('related_id', (string) $recipient->getKey())
            ->get(['id', 'context']);

        $erased = 0;

        foreach ($rows as $row) {
            /** @var array<string, mixed> $context */
            $context = json_decode((string) $row->context, true) ?: [];

            // Only the name keys App\Domain\Delivery\Mail\MailContext defines are rewritten.
            // `recipient_name` is who the message was addressed to; `actor_name` is who a
            // notice is *about*, which on a decline notice sent to the sender is this same
            // person. Nothing else in the context is contact data — the kind, the agreement
            // title, and the timestamps are what make the row useful for explaining what
            // happened, and erasing them would destroy the record of the contact rather
            // than the contact details in it.
            foreach (['recipient_name', 'actor_name'] as $key) {
                if (($context[$key] ?? null) !== null) {
                    $context[$key] = self::NAME_TOMBSTONE;
                }
            }

            $context['contact_erased_at'] = $erasedAt->toIso8601String();

            $erased += $this->db->table('outbound_mails')
                ->where('id', $row->id)
                ->update([
                    'to_email' => $tombstone,
                    'to_name' => self::NAME_TOMBSTONE,
                    'subject' => self::SUBJECT_TOMBSTONE,
                    'context' => json_encode($context, JSON_THROW_ON_ERROR),
                ]);
        }

        return $erased;
    }
}
