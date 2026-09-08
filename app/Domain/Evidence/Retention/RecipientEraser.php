<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Retention;

use App\Domain\Evidence\Retention\Exceptions\LegalHoldActive;
use App\Domain\Evidence\Retention\Exceptions\RetentionRefused;
use App\Domain\Identity\Audit\AuditActor;
use App\Domain\Identity\Audit\AuditRecorder;
use App\Domain\Signing\Envelopes\EnvelopeState;
use App\Domain\Signing\Models\Envelope;
use App\Domain\Signing\Models\EnvelopeRecipient;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

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
 * | `outbound_mails` | any occurrence of the address inside `context` | the tombstone address |
 * | `outbound_mail_events` | any occurrence of the address inside `payload` | the tombstone address |
 *
 * **Every kind of message, not only the ones addressed by recipient row.** `MailOutbox`
 * records an invitation, a reminder, and a one-time code against the recipient, but a
 * completion, cancellation, expiry, decline, or admin-failure notice against the *envelope*
 * — so matching on `related_type = recipient` alone left the erased address in cleartext on
 * every terminal notice the person received, which is most of them
 * (docs/security/review-2026-09.md, and issue #89). The match is now the union of "this
 * message was about this recipient" and "this message went to this address, about this
 * agreement", which reaches every kind while leaving messages to the *other* parties on the
 * same envelope exactly as they were.
 *
 * The address is also scrubbed out of the JSON on those rows rather than only out of the
 * columns. A sender-authored `context.reason` and a provider's `outbound_mail_events.payload`
 * are both free text that can quote a mailbox — `MailErrorRedactor` strips addresses from
 * provider bodies on the way in, and this is the belt to that brace for rows written before
 * it, by another path, or by a provider field it did not anticipate.
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

    /**
     * @param  string  $reason  Why the erasure was requested. Stored on the audit event; a
     *                          privacy action with no recorded basis is one nobody can
     *                          later justify.
     * @return array{recipient: string, envelope: string, tombstone_email: string, columns: list<string>, outbound_mail_rows_erased: int, outbound_mail_event_rows_scrubbed: int}
     *
     * @throws LegalHoldActive When the envelope is held.
     * @throws RetentionRefused When the reason is empty, or the recipient is already erased.
     */
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
        $columns = [
            'envelope_recipients.name',
            'envelope_recipients.email',
            'envelope_recipients.identity_snapshot',
            'outbound_mails.to_email',
            'outbound_mails.to_name',
            'outbound_mails.subject',
            'outbound_mails.context',
            'outbound_mail_events.payload',
        ];

        // Captured before the row is rewritten: the outbox match and the JSON scrub both
        // need the address that is about to stop existing.
        $previousEmail = (string) $recipient->email;

        $counts = $this->db->transaction(function () use ($recipient, $previousEmail, $tombstone, $erasedAt, $actor, $reason, $envelope, $columns): array {
            $recipient->name = self::NAME_TOMBSTONE;
            $recipient->email = $tombstone;
            $recipient->identity_snapshot = [
                'erased' => true,
                'erased_at' => $erasedAt->toIso8601String(),
            ];
            $recipient->save();

            $counts = $this->eraseOutboundMail($recipient, $envelope, $previousEmail, $tombstone, $erasedAt);

            $this->audit->record($actor, self::ERASED, $recipient, [
                'recipient' => $recipient->public_id,
                'envelope' => $envelope->public_id,
                'workspace_id' => $envelope->workspace_id,
                'reason' => $reason,
                'erased_at' => $erasedAt->toIso8601String(),
                // What was rewritten, never what it used to say.
                'erased_columns' => $columns,
                'outbound_mail_rows_erased' => $counts['mails'],
                'outbound_mail_event_rows_scrubbed' => $counts['events'],
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

            return $counts;
        });

        return [
            'recipient' => $recipient->public_id,
            'envelope' => $envelope->public_id,
            'tombstone_email' => $tombstone,
            'columns' => $columns,
            'outbound_mail_rows_erased' => $counts['mails'],
            'outbound_mail_event_rows_scrubbed' => $counts['events'],
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
     * ## What is matched
     *
     * The union of two conditions, because `MailOutbox` does not record one thing:
     *
     *  1. **`related` is this recipient.** An invitation, a reminder, and a one-time code are
     *     recorded against the recipient row, so this is how they are found — whatever
     *     address they were sent to, since the row is about this person either way.
     *  2. **`related` is somewhere in this envelope, and `to_email` is the erased address.**
     *     A completion, cancellation, expiry, decline, or admin-failure notice is recorded
     *     against the *envelope*, so condition 1 alone left the address in cleartext on every
     *     terminal notice this person received (issue #89).
     *
     * The address is only ever matched **inside one envelope**, which is what keeps the
     * original objection to address matching answered: a different party who happens to
     * share the mailbox keeps every message about their own agreements. Within this one
     * agreement, a message sent to that mailbox went to the person asking, and messages to
     * the other parties on it are untouched because their address does not match.
     *
     * @return array{mails: int, events: int}
     */
    private function eraseOutboundMail(
        EnvelopeRecipient $recipient,
        Envelope $envelope,
        string $previousEmail,
        string $tombstone,
        CarbonImmutable $erasedAt,
    ): array {
        $recipientType = $recipient->getMorphClass();

        // Every recipient row on this envelope, so a notice recorded against a *sibling*
        // recipient but addressed to this mailbox is in scope too. Read through the query
        // builder for the same reason the sweep does: no scope should be able to narrow the
        // set of rows an erasure has to reach.
        $envelopeRecipientIds = $this->db->table('envelope_recipients')
            ->where('envelope_id', $envelope->getKey())
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        $rows = $this->db->table('outbound_mails')
            ->where(function (Builder $match) use ($recipientType, $recipient, $envelope, $envelopeRecipientIds, $previousEmail): void {
                $match
                    ->where(fn (Builder $aboutThisRecipient) => $aboutThisRecipient
                        ->where('related_type', $recipientType)
                        ->where('related_id', (string) $recipient->getKey()))
                    ->orWhere(function (Builder $toThisAddress) use ($recipientType, $envelope, $envelopeRecipientIds, $previousEmail): void {
                        $toThisAddress
                            // Both sides through the database's own `lower()`, so the match
                            // means the same thing on SQLite, MySQL, and MariaDB rather than
                            // depending on PHP and SQL agreeing about case folding.
                            ->whereRaw('lower(to_email) = lower(?)', [$previousEmail])
                            ->where(fn (Builder $withinThisEnvelope) => $withinThisEnvelope
                                ->where(fn (Builder $onEnvelope) => $onEnvelope
                                    ->where('related_type', $envelope->getMorphClass())
                                    ->where('related_id', (string) $envelope->getKey()))
                                ->orWhere(fn (Builder $onSibling) => $onSibling
                                    ->where('related_type', $recipientType)
                                    ->whereIn('related_id', $envelopeRecipientIds)));
                    });
            })
            ->orderBy('id')
            ->get(['id', 'context']);

        $erased = 0;
        $events = 0;

        foreach ($rows as $row) {
            /** @var array<string, mixed> $context */
            $context = json_decode((string) $row->context, true) ?: [];

            // Only the name keys App\Domain\Delivery\Mail\MailContext defines are rewritten.
            // `recipient_name` is who the message was addressed to; `actor_name` is who a
            // notice is *about*, which on a decline notice is this same person whenever the
            // row is in scope. Nothing else in the context is contact data — the kind, the agreement
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
                    // Scrubbed after encoding, so the address goes from wherever it ended up
                    // — a sender-authored `reason`, a `reference`, a key added by a later
                    // release — rather than only from the two keys named above.
                    'context' => self::scrubAddress(
                        json_encode($context, JSON_THROW_ON_ERROR),
                        $previousEmail,
                        $tombstone,
                    ),
                ]);

            $events += $this->scrubMailEvents((int) $row->id, $previousEmail, $tombstone);
        }

        return ['mails' => $erased, 'events' => $events];
    }

    /**
     * Take the address out of the provider feedback recorded against one message.
     *
     * `outbound_mail_events` is append-only everywhere else, and deliberately so: an event
     * is a claim somebody made at a point in time. This rewrites the address inside
     * `payload` and nothing else — the source, the event name, the Message-ID, and the
     * timestamps all stay, so the claim survives and only the mailbox it quoted is gone.
     * `MailErrorRedactor` already replaces addresses in provider bodies on the way in; this
     * covers the rows it did not write and the fields it did not anticipate.
     *
     * @return int The number of event rows that actually contained the address.
     */
    private function scrubMailEvents(int $mailId, string $previousEmail, string $tombstone): int
    {
        $scrubbed = 0;

        $events = $this->db->table('outbound_mail_events')
            ->where('outbound_mail_id', $mailId)
            ->orderBy('id')
            ->get(['id', 'payload']);

        foreach ($events as $event) {
            if ($event->payload === null) {
                continue;
            }

            $payload = (string) $event->payload;
            $clean = self::scrubAddress($payload, $previousEmail, $tombstone);

            if ($clean === $payload) {
                continue;
            }

            $scrubbed += $this->db->table('outbound_mail_events')
                ->where('id', $event->id)
                ->update(['payload' => $clean]);
        }

        return $scrubbed;
    }

    /**
     * Replace every occurrence of one address in a JSON document with the tombstone.
     *
     * Applied to the encoded string rather than to a decoded tree, so an address nested at
     * any depth, in a key or a value, in prose or on its own, goes in one pass. The
     * tombstone is plain ASCII mailbox text with nothing JSON has to escape, so a valid
     * document stays valid. The address is searched for in both its literal form and the
     * form `json_encode()` would have written it in, because a non-ASCII local part is
     * stored as `\uXXXX` escapes and would otherwise survive the pass that claims to have
     * removed it. Case-insensitive: a provider echoing a mailbox back in a different case is
     * echoing back the same person.
     */
    private static function scrubAddress(string $json, string $previousEmail, string $tombstone): string
    {
        if ($previousEmail === '') {
            return $json;
        }

        $needles = [$previousEmail];
        $escaped = trim(json_encode($previousEmail, JSON_THROW_ON_ERROR), '"');

        if ($escaped !== $previousEmail) {
            $needles[] = $escaped;
        }

        return str_ireplace($needles, $tombstone, $json);
    }
}
