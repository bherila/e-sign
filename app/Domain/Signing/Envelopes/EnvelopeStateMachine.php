<?php

declare(strict_types=1);

namespace App\Domain\Signing\Envelopes;

use App\Domain\Preparation\Schema\FieldDefinition;
use App\Domain\Signing\Contracts\AssurancePolicyCheck;
use App\Domain\Signing\Contracts\EnvelopeEventSink;
use App\Domain\Signing\Exceptions\ConsentMismatch;
use App\Domain\Signing\Exceptions\FieldSubmissionRejected;
use App\Domain\Signing\Exceptions\IllegalTransition;
use App\Domain\Signing\Exceptions\MissingArtifactReference;
use App\Domain\Signing\Exceptions\RecipientNotEligible;
use App\Domain\Signing\Exceptions\RequiredFieldsMissing;
use App\Domain\Signing\Exceptions\SendPreconditionsFailed;
use App\Domain\Signing\Exceptions\StaleEnvelope;
use App\Domain\Signing\Exceptions\StaleReview;
use App\Domain\Signing\Fields\CanonicalValue;
use App\Domain\Signing\Fields\FieldMateriality;
use App\Domain\Signing\Fields\FieldValueValidator;
use App\Domain\Signing\Fields\MaterialValues;
use App\Domain\Signing\Models\Envelope;
use App\Domain\Signing\Models\EnvelopeFieldValue;
use App\Domain\Signing\Models\EnvelopeRecipient;
use App\Domain\Signing\Models\RecipientAttestation;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The single authority over an envelope's lifecycle.
 *
 * AGENTS.md: "One state machine. The Firma facade and the native API call the same domain
 * services. Never put signing rules in a compatibility controller." Every rule about who may
 * act, when, and what it means lives here, so the two HTTP surfaces and the guest signing UI
 * are translations rather than three implementations that drift.
 *
 * ## How a transition is made safe
 *
 * Every method that changes anything does the same four things, in the same order:
 *
 * 1. Open a transaction.
 * 2. Re-read the row with `lockForUpdate()`.
 * 3. Check the caller's expected `version` against the row's, then check the transition is
 *    legal from the state it actually holds.
 * 4. Write with `UPDATE ... WHERE version = <expected>` and assert exactly one row changed.
 *
 * Steps 2 and 4 look redundant and are not. `lockForUpdate()` compiles to nothing on SQLite
 * (`SQLiteGrammar::compileLock()` returns an empty string), so on the development and test
 * engine the version predicate is the *only* thing standing between two callers; on MySQL
 * and MariaDB the lock serialises them and the predicate confirms it. Writing the guard so
 * that it holds without the lock is what makes the same code correct on all three engines,
 * which is the portability requirement in docs/adr/0002-supported-databases.md. That is also
 * why the races are testable without threads: two model instances read the same version, and
 * whichever writes second finds no row to update.
 *
 * Losing the compare-and-swap raises {@see StaleEnvelope} (or {@see StaleReview} for an
 * acceptance, which is the same failure with a meaning attached). Exactly one of two racing
 * callers wins, and the loser is never told it succeeded — docs/ARCHITECTURE.md invariant 6.
 *
 * ## Events
 *
 * The sink is called inside the transaction, so an event and the change it describes commit
 * or roll back together. See {@see EnvelopeEventSink} for why an implementation must be a
 * database write and nothing else.
 *
 * ## Not here
 *
 * Recipient credentials and sessions are issue #25; signature capture and consent rendering
 * are #26; rendering, sealing, validating and publishing the artifact are #28. This module
 * takes a `sessionRef` it does not interpret, a captured value it does not adopt, and an
 * `artifactRef` it cannot verify, and it refuses to pretend otherwise — `markCompleted()`
 * exists precisely so that completion is something the finalizer asserts after the fact.
 */
final readonly class EnvelopeStateMachine
{
    /**
     * The whole transition table, in one place.
     *
     * Keyed by transition, valued by the states it may be applied from. Anything not listed
     * is illegal; docs/signing/state-machine.md renders this for humans and a test asserts
     * the two agree.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        'send' => ['draft'],
        'submit_values' => ['sent', 'in_progress'],
        'set_sender_values' => ['draft', 'sent', 'in_progress'],
        'accept' => ['sent', 'in_progress'],
        'decline' => ['sent', 'in_progress'],
        'cancel' => ['draft', 'sent', 'in_progress'],
        'expire' => ['sent', 'in_progress'],
        'freeze_for_parallel' => ['sent', 'in_progress'],
        'mark_completed' => ['finalizing'],
        'mark_finalization_failed' => ['finalizing'],
        'retry_finalization' => ['finalization_failed'],
    ];

    /** Firma caps its cancellation reason at 500 characters; the column matches. */
    public const MAX_REASON_LENGTH = 500;

    public function __construct(
        private EnvelopeEventSink $sink,
        private AssurancePolicyCheck $assurance,
    ) {}

    /**
     * Issue the invitations: validate everything that can still be fixed, then release the
     * first stage.
     *
     * This is the last cheap moment. After it, correcting material content means a new
     * envelope and renewed signatures (docs/ARCHITECTURE.md invariant 4), so the gate is
     * strict and reports every problem at once ({@see SendPreconditionsFailed}).
     *
     * In parallel mode the content freezes here rather than at the first acceptance, because
     * docs/HANDOFF.md section 6 requires that all material values be collected before anyone
     * can assent: two people must never accept materially different text because a third
     * edited a shared field between them.
     *
     * @throws IllegalTransition|SendPreconditionsFailed|StaleEnvelope
     */
    public function send(Envelope $envelope, ?int $expectedVersion = null): TransitionResult
    {
        return $this->withLockedEnvelope(
            $envelope,
            $expectedVersion,
            function (Envelope $locked) use ($envelope): TransitionResult {
                $from = $this->assertLegal('send', $locked);
                $this->assertSendable($locked);

                $now = CarbonImmutable::now();
                $changes = [
                    'state' => EnvelopeState::Sent->value,
                    'sent_at' => $now,
                    'expires_at' => $locked->expiration_hours === null
                        ? null
                        : $now->addHours($locked->expiration_hours),
                ];

                if ($locked->signing_mode->freezesAtSend()) {
                    $changes['content_frozen_at'] = $now;
                }

                $this->commitEnvelope($locked, $changes);

                if ($locked->signing_mode->freezesAtSend()) {
                    $this->stampMaterialValuesFrozen($locked, $now);
                    $this->activateAllPending($locked, $now);
                } else {
                    $this->activateStage($locked, $this->firstPendingStage($locked) ?? 1, $now);
                }

                $this->sink->record($locked, EnvelopeEvent::Sent, [
                    'expires_at' => $locked->expires_at?->toIso8601String(),
                    'signing_mode' => $locked->signing_mode->value,
                    'content_frozen' => $locked->isContentFrozen(),
                ]);

                $this->syncBack($envelope, $locked);

                return new TransitionResult($locked, $from, $locked->state, [EnvelopeEvent::Sent]);
            },
        );
    }

    /**
     * Freeze every material value so nobody can change the agreement's content.
     *
     * Applied automatically by {@see send()} in parallel mode. Exposed because the freeze is
     * a fact worth being able to state and assert on its own, and because a workflow that
     * decides mid-flight to stop accepting material edits should not have to fake an
     * acceptance to do it. Idempotent: an already-frozen envelope is returned unchanged
     * rather than re-stamped, so a second call cannot move a freeze time that evidence
     * refers to.
     *
     * @throws IllegalTransition|StaleEnvelope
     */
    public function freezeForParallel(Envelope $envelope, ?int $expectedVersion = null): TransitionResult
    {
        return $this->withLockedEnvelope(
            $envelope,
            $expectedVersion,
            function (Envelope $locked) use ($envelope): TransitionResult {
                $from = $this->assertLegal('freeze_for_parallel', $locked);

                if ($locked->isContentFrozen()) {
                    return new TransitionResult($locked, $from, $from);
                }

                $now = CarbonImmutable::now();
                $this->commitEnvelope($locked, ['content_frozen_at' => $now]);
                $this->stampMaterialValuesFrozen($locked, $now);
                $this->syncBack($envelope, $locked);

                return new TransitionResult($locked, $from, $locked->state);
            },
        );
    }

    /**
     * Record values a recipient completed themselves.
     *
     * Enforces docs/ARCHITECTURE.md invariant 1 (their own fields, only while eligible) and
     * the second half of invariant 4 (after the freeze, only signer-specific fields, and only
     * from someone who has not signed — which an `active` recipient by definition has not).
     *
     * @param  array<string, mixed>  $values  Schema field id => value.
     *
     * @throws IllegalTransition|RecipientNotEligible|FieldSubmissionRejected|StaleEnvelope
     */
    public function submitValues(
        EnvelopeRecipient $recipient,
        array $values,
        ?int $expectedEnvelopeVersion = null,
    ): ValueSubmissionResult {
        $envelope = $recipient->envelope()->firstOrFail();

        return $this->withLockedEnvelope(
            $envelope,
            $expectedEnvelopeVersion,
            function (Envelope $locked) use ($recipient, $values): ValueSubmissionResult {
                $this->assertLegal('submit_values', $locked);
                $lockedRecipient = $this->lockRecipient($recipient);

                if ($lockedRecipient->state !== RecipientState::Active) {
                    throw RecipientNotEligible::notActive($lockedRecipient, 'submit values');
                }

                $written = [];

                foreach ($values as $fieldId => $value) {
                    $field = $this->assertRecipientMaySubmit($locked, $lockedRecipient, (string) $fieldId);
                    $this->writeValue(
                        $locked,
                        $field,
                        FieldValueValidator::normalize($field, $value),
                        ValueSource::Recipient,
                        $lockedRecipient->getKey(),
                    );
                    $written[] = $field->id;
                }

                // A submission that wrote something advances the envelope: the version bump
                // is what makes an earlier review stale, and `sent` stops being accurate the
                // moment somebody acts. A submission that wrote nothing is not a transition,
                // and bumping the version for it would invalidate everybody else's review
                // for no reason.
                if ($written !== []) {
                    $changes = [];

                    if ($locked->state === EnvelopeState::Sent) {
                        $changes['state'] = EnvelopeState::InProgress->value;
                    }

                    $this->commitEnvelope($locked, $changes);
                }
                $this->syncBack($recipient, $lockedRecipient);

                return new ValueSubmissionResult(
                    $locked,
                    $written,
                    MaterialValues::forEnvelope($locked),
                    $locked->version,
                );
            },
        );
    }

    /**
     * Record values the sending workspace supplied: prefills, and corrections made while the
     * content is still open.
     *
     * A sender may fill a read-only field — that is what read-only means, and the fixture
     * schema prefills a printed name that way. A sender may never supply a signature or
     * initials, which is signing on somebody else's behalf, and never a service-supplied
     * field, which would let the sender state when a recipient signed.
     *
     * @param  array<string, mixed>  $values  Schema field id => value.
     *
     * @throws IllegalTransition|FieldSubmissionRejected|StaleEnvelope
     */
    public function setSenderValues(
        Envelope $envelope,
        array $values,
        ?int $expectedVersion = null,
    ): ValueSubmissionResult {
        return $this->withLockedEnvelope(
            $envelope,
            $expectedVersion,
            function (Envelope $locked) use ($envelope, $values): ValueSubmissionResult {
                $this->assertLegal('set_sender_values', $locked);

                $schema = $locked->fieldSchema();
                $written = [];

                foreach ($values as $fieldId => $value) {
                    $fieldId = (string) $fieldId;
                    $field = $schema->field($fieldId);

                    if ($field === null) {
                        throw FieldSubmissionRejected::unknownField($fieldId);
                    }

                    if (FieldMateriality::isServiceSupplied($field->type)) {
                        throw FieldSubmissionRejected::serviceSupplied($fieldId);
                    }

                    if (in_array($field->type->value, ['signature', 'initials'], true)) {
                        throw FieldSubmissionRejected::requiresRecipient($fieldId);
                    }

                    // Once content is frozen, a sender is exactly as constrained as a
                    // signer: the material text is what people are agreeing to.
                    if ($locked->isContentFrozen() && FieldMateriality::isMaterial($field->type)) {
                        throw FieldSubmissionRejected::frozen($fieldId);
                    }

                    $this->writeValue(
                        $locked,
                        $field,
                        FieldValueValidator::normalize($field, $value),
                        ValueSource::Sender,
                        $this->recipientIdFor($locked, $field),
                    );
                    $written[] = $field->id;
                }

                if ($written !== []) {
                    $this->commitEnvelope($locked, []);
                }

                $this->syncBack($envelope, $locked);

                return new ValueSubmissionResult(
                    $locked,
                    $written,
                    MaterialValues::forEnvelope($locked),
                    $locked->version,
                );
            },
        );
    }

    /**
     * Take one recipient's assent and write the attestation that is that assent.
     *
     * The order of the checks is the design. The idempotency lookup runs *first*, because a
     * retried acceptance necessarily arrives against a version its own predecessor moved; if
     * the staleness check ran first, every retry would be refused as stale and invariant 7
     * would be unimplementable. Only once the request is known to be new does the version
     * compare-and-swap decide the race, and only then are consent, eligibility, required
     * fields, and the reviewed material digest checked.
     *
     * The first acceptance on an envelope freezes its content (invariant 4). When the last
     * outstanding recipient signs, the envelope moves to `finalizing` — never straight to
     * `completed`, which is the finalizer's assertion after a validated artifact exists.
     *
     * @throws IllegalTransition|RecipientNotEligible|ConsentMismatch|RequiredFieldsMissing|StaleReview
     */
    public function accept(EnvelopeRecipient $recipient, AcceptanceRequest $request): AcceptanceResult
    {
        $envelope = $recipient->envelope()->firstOrFail();

        return DB::transaction(function () use ($envelope, $recipient, $request): AcceptanceResult {
            $locked = $this->lockEnvelope($envelope);

            $replay = RecipientAttestation::query()
                ->where('recipient_id', $recipient->getKey())
                ->where('session_ref', $request->sessionRef)
                ->first();

            if ($replay !== null) {
                return $this->replayAcceptance($locked, $recipient, $replay, $request);
            }

            if ($locked->version !== $request->reviewedEnvelopeVersion) {
                throw StaleReview::envelopeVersionMoved($request->reviewedEnvelopeVersion, $locked->version);
            }

            $from = $this->assertLegal('accept', $locked);

            if ($request->consentPolicyVersion !== $locked->consent_policy_version) {
                throw new ConsentMismatch($request->consentPolicyVersion, $locked->consent_policy_version);
            }

            $lockedRecipient = $this->lockRecipient($recipient);

            if ($lockedRecipient->state !== RecipientState::Active) {
                throw RecipientNotEligible::notActive($lockedRecipient, 'accept');
            }

            $this->assertRequiredFieldsComplete($locked, $lockedRecipient);

            $material = MaterialValues::forEnvelope($locked);

            if ($material !== $request->reviewedMaterialSha256) {
                throw StaleReview::materialValuesMoved($request->reviewedMaterialSha256, $material);
            }

            $now = CarbonImmutable::now()->utc()->startOfSecond();
            $attestation = $this->writeAttestation($locked, $lockedRecipient, $request, $material, $now);

            $this->commitRecipient($lockedRecipient, [
                'state' => RecipientState::Signed->value,
                'signed_at' => $now,
            ]);

            $changes = [];
            $freezingNow = ! $locked->isContentFrozen();

            if ($freezingNow) {
                $changes['content_frozen_at'] = $now;
            }

            $everyoneSigned = $this->outstandingRecipients($locked) === 0;
            $changes['state'] = $everyoneSigned
                ? EnvelopeState::Finalizing->value
                : EnvelopeState::InProgress->value;

            $this->commitEnvelope($locked, $changes);

            if ($freezingNow) {
                $this->stampMaterialValuesFrozen($locked, $now);
            }

            if (! $everyoneSigned && $this->activeRecipients($locked) === 0) {
                $nextStage = $this->firstPendingStage($locked);

                if ($nextStage !== null) {
                    $this->activateStage($locked, $nextStage, $now);
                }
            }

            $this->sink->record($locked, EnvelopeEvent::RecipientSigned, [
                'recipient' => $lockedRecipient->public_id,
                'schema_recipient_id' => $lockedRecipient->schema_recipient_id,
                'attestation' => $attestation->public_id,
                'attestation_sha256' => $attestation->attestation_sha256,
                'signed_at' => $now->toIso8601String(),
            ]);

            $this->syncBack($envelope, $locked);
            $this->syncBack($recipient, $lockedRecipient);

            return new AcceptanceResult(
                $locked,
                $lockedRecipient,
                $attestation,
                false,
                $from,
                $locked->state,
                [EnvelopeEvent::RecipientSigned],
            );
        });
    }

    /**
     * One recipient refuses, and the envelope is declined.
     *
     * A decline is terminal for the whole envelope rather than for one party: the remaining
     * signers are not asked to execute an agreement one of the parties has rejected.
     * Re-sending is a new envelope.
     *
     * @throws IllegalTransition|RecipientNotEligible|StaleEnvelope
     */
    public function decline(
        EnvelopeRecipient $recipient,
        ?string $reason = null,
        ?int $expectedEnvelopeVersion = null,
    ): TransitionResult {
        $reason = $this->reason($reason);
        $envelope = $recipient->envelope()->firstOrFail();

        return $this->withLockedEnvelope(
            $envelope,
            $expectedEnvelopeVersion,
            function (Envelope $locked) use ($envelope, $recipient, $reason): TransitionResult {
                $from = $this->assertLegal('decline', $locked);
                $lockedRecipient = $this->lockRecipient($recipient);

                if ($lockedRecipient->state !== RecipientState::Active) {
                    throw RecipientNotEligible::notActive($lockedRecipient, 'decline');
                }

                $now = CarbonImmutable::now();

                $this->commitRecipient($lockedRecipient, [
                    'state' => RecipientState::Declined->value,
                    'declined_at' => $now,
                    'decline_reason' => $reason,
                ]);

                $this->commitEnvelope($locked, [
                    'state' => EnvelopeState::Declined->value,
                    'declined_at' => $now,
                ]);

                $this->sink->record($locked, EnvelopeEvent::RecipientDeclined, [
                    'recipient' => $lockedRecipient->public_id,
                    'schema_recipient_id' => $lockedRecipient->schema_recipient_id,
                    'reason' => $reason,
                ]);
                $this->sink->record($locked, EnvelopeEvent::Declined, [
                    'declined_by' => $lockedRecipient->public_id,
                    'reason' => $reason,
                ]);

                $this->syncBack($envelope, $locked);
                $this->syncBack($recipient, $lockedRecipient);

                return new TransitionResult($locked, $from, $locked->state, [
                    EnvelopeEvent::RecipientDeclined,
                    EnvelopeEvent::Declined,
                ]);
            },
        );
    }

    /**
     * Withdraw the envelope.
     *
     * Legal from `draft`, `sent`, and `in_progress`, and from nowhere else. In particular not
     * from `finalizing`: at that point everybody has signed, and an artifact is being
     * produced from their acceptances. Cancelling then would either discard an executed
     * agreement or race the finalizer for the right to describe the same envelope, and the
     * compare-and-swap makes sure only one of those two ever happens.
     *
     * @throws IllegalTransition|StaleEnvelope
     */
    public function cancel(Envelope $envelope, ?string $reason = null, ?int $expectedVersion = null): TransitionResult
    {
        $reason = $this->reason($reason);

        return $this->withLockedEnvelope(
            $envelope,
            $expectedVersion,
            function (Envelope $locked) use ($envelope, $reason): TransitionResult {
                $from = $this->assertLegal('cancel', $locked);
                $now = CarbonImmutable::now();

                $this->commitEnvelope($locked, [
                    'state' => EnvelopeState::Cancelled->value,
                    'cancelled_at' => $now,
                    'cancel_reason' => $reason,
                ]);

                $this->sink->record($locked, EnvelopeEvent::Cancelled, [
                    'reason' => $reason,
                    'cancelled_from' => $from->value,
                ]);

                $this->syncBack($envelope, $locked);

                return new TransitionResult($locked, $from, $locked->state, [EnvelopeEvent::Cancelled]);
            },
        );
    }

    /**
     * Apply an expiry that has arrived.
     *
     * Refuses an envelope with no expiry or one that is not due yet: expiry is a fact about
     * the clock, and a caller that wants to stop an envelope early is withdrawing it, which
     * records a reason and says so.
     *
     * @throws IllegalTransition|StaleEnvelope
     */
    public function expire(Envelope $envelope, ?int $expectedVersion = null): TransitionResult
    {
        return $this->withLockedEnvelope(
            $envelope,
            $expectedVersion,
            function (Envelope $locked) use ($envelope): TransitionResult {
                $from = $this->assertLegal('expire', $locked);
                $now = CarbonImmutable::now();

                if ($locked->expires_at === null || $locked->expires_at->greaterThan($now)) {
                    throw IllegalTransition::expiryNotDue($locked->expires_at);
                }

                $this->commitEnvelope($locked, [
                    'state' => EnvelopeState::Expired->value,
                    'expired_at' => $now,
                ]);

                $this->sink->record($locked, EnvelopeEvent::Expired, [
                    'expired_at' => $now->toIso8601String(),
                    'expires_at' => $locked->expires_at?->toIso8601String(),
                ]);

                $this->syncBack($envelope, $locked);

                return new TransitionResult($locked, $from, $locked->state, [EnvelopeEvent::Expired]);
            },
        );
    }

    /**
     * The finalizer asserts that a validated, durably stored, retrievable artifact exists.
     *
     * This module cannot check that claim — it has no access to the bytes — so it enforces
     * the part it can: there is no completion without a reference to the thing that makes it
     * true (docs/ARCHITECTURE.md invariant 5). The completion event is published from here
     * and from nowhere else, which is why `signing_request.completed` is emitted later than
     * the profile's own timing and the capability matrix records that as intentional.
     *
     * @throws IllegalTransition|MissingArtifactReference|StaleEnvelope
     */
    public function markCompleted(
        Envelope $envelope,
        string $artifactRef,
        ?int $expectedVersion = null,
    ): TransitionResult {
        if (trim($artifactRef) === '') {
            throw new MissingArtifactReference;
        }

        return $this->withLockedEnvelope(
            $envelope,
            $expectedVersion,
            function (Envelope $locked) use ($envelope, $artifactRef): TransitionResult {
                $from = $this->assertLegal('mark_completed', $locked);
                $now = CarbonImmutable::now();

                $this->commitEnvelope($locked, [
                    'state' => EnvelopeState::Completed->value,
                    'completed_at' => $now,
                    'artifact_ref' => trim($artifactRef),
                    'finalization_failure_reason' => null,
                ]);

                $this->sink->record($locked, EnvelopeEvent::Completed, [
                    'artifact_ref' => $locked->artifact_ref,
                    'completed_at' => $now->toIso8601String(),
                ]);

                $this->syncBack($envelope, $locked);

                return new TransitionResult($locked, $from, $locked->state, [EnvelopeEvent::Completed]);
            },
        );
    }

    /**
     * Finalization was attempted and did not produce a publishable artifact.
     *
     * The envelope stays visibly failed and never becomes completed. AGENTS.md: "A completion
     * event is published only after the final PDF is generated, validated, durably stored,
     * and retrievable." A failure that quietly looked like success would be the exact
     * opposite of that.
     *
     * @throws IllegalTransition|StaleEnvelope
     */
    public function markFinalizationFailed(
        Envelope $envelope,
        string $reason,
        ?int $expectedVersion = null,
    ): TransitionResult {
        $reason = $this->reason($reason) ?? 'Finalization failed for an unrecorded reason.';

        return $this->withLockedEnvelope(
            $envelope,
            $expectedVersion,
            function (Envelope $locked) use ($envelope, $reason): TransitionResult {
                $from = $this->assertLegal('mark_finalization_failed', $locked);

                $this->commitEnvelope($locked, [
                    'state' => EnvelopeState::FinalizationFailed->value,
                    'finalization_failure_reason' => $reason,
                ]);

                $this->sink->record($locked, EnvelopeEvent::FinalizationFailed, ['reason' => $reason]);
                $this->syncBack($envelope, $locked);

                return new TransitionResult($locked, $from, $locked->state, [EnvelopeEvent::FinalizationFailed]);
            },
        );
    }

    /**
     * Put a failed finalization back in the queue.
     *
     * The acceptances are untouched and remain valid: what failed was producing the artifact,
     * not the agreement. Retrying reuses the same immutable inputs, which is the property the
     * staged publication in docs/ARCHITECTURE.md depends on.
     *
     * @throws IllegalTransition|StaleEnvelope
     */
    public function retryFinalization(Envelope $envelope, ?int $expectedVersion = null): TransitionResult
    {
        return $this->withLockedEnvelope(
            $envelope,
            $expectedVersion,
            function (Envelope $locked) use ($envelope): TransitionResult {
                $from = $this->assertLegal('retry_finalization', $locked);

                $this->commitEnvelope($locked, [
                    'state' => EnvelopeState::Finalizing->value,
                    'finalization_failure_reason' => null,
                ]);

                $this->syncBack($envelope, $locked);

                return new TransitionResult($locked, $from, $locked->state);
            },
        );
    }

    /**
     * The digest a signing session must quote back when it accepts.
     *
     * Read outside a transition, so it is a snapshot of this instant and nothing more. That
     * is exactly right: the recipient reviews what it describes, and {@see accept()} refuses
     * if the world moved in between.
     */
    public function materialValuesDigest(Envelope $envelope): string
    {
        return MaterialValues::forEnvelope($envelope);
    }

    // ---------------------------------------------------------------------------------
    // Locking, compare-and-swap, and the legality table
    // ---------------------------------------------------------------------------------

    /**
     * Run `$work` against a locked, version-checked copy of the envelope.
     *
     * `$expectedVersion` defaults to the version on the instance the caller handed in, which
     * makes optimistic concurrency the default rather than something a caller has to
     * remember: acting on a model you read before somebody else changed it is exactly the
     * race that must not succeed silently. A caller that genuinely wants "whatever is true
     * now" re-reads the row first, which is a visible decision.
     *
     * @template TResult
     *
     * @param  Closure(Envelope): TResult  $work
     * @return TResult
     */
    private function withLockedEnvelope(Envelope $envelope, ?int $expectedVersion, Closure $work): mixed
    {
        $expected = $expectedVersion ?? $envelope->version;

        return DB::transaction(function () use ($envelope, $expected, $work) {
            $locked = $this->lockEnvelope($envelope);

            if ($locked->version !== $expected) {
                throw StaleEnvelope::envelope($expected);
            }

            return $work($locked);
        });
    }

    private function lockEnvelope(Envelope $envelope): Envelope
    {
        $locked = Envelope::query()->whereKey($envelope->getKey())->lockForUpdate()->first();

        if ($locked === null) {
            throw (new ModelNotFoundException)->setModel(Envelope::class, [$envelope->getKey()]);
        }

        return $locked;
    }

    /**
     * Always taken after the envelope lock.
     *
     * A fixed lock order is what stops two transitions on the same envelope from deadlocking
     * on MySQL and MariaDB when one starts from the envelope and the other from a recipient.
     */
    private function lockRecipient(EnvelopeRecipient $recipient): EnvelopeRecipient
    {
        $locked = EnvelopeRecipient::query()->whereKey($recipient->getKey())->lockForUpdate()->first();

        if ($locked === null) {
            throw (new ModelNotFoundException)->setModel(EnvelopeRecipient::class, [$recipient->getKey()]);
        }

        return $locked;
    }

    /**
     * The compare-and-swap itself.
     *
     * One statement, guarded by the version the caller read. `$changes` carries scalars only
     * — this goes through the query builder, which does not run casts or model events — and
     * that is deliberate for a second reason too: a compare-and-swap has to be a single
     * `UPDATE`, so it cannot be an Eloquent save with observers in the middle.
     *
     * The version advances on every transition, including ones whose only effect is a
     * changed field value. That is what makes an earlier review detectably stale.
     *
     * @param  array<string, mixed>  $changes
     */
    private function commitEnvelope(Envelope $locked, array $changes): void
    {
        $expected = $locked->version;
        $changes['version'] = $expected + 1;
        $changes['updated_at'] = CarbonImmutable::now();

        $affected = Envelope::query()
            ->whereKey($locked->getKey())
            ->where('version', $expected)
            ->update($changes);

        if ($affected !== 1) {
            throw StaleEnvelope::envelope($expected);
        }

        $locked->refresh();
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function commitRecipient(EnvelopeRecipient $locked, array $changes): void
    {
        $expected = $locked->version;
        $changes['version'] = $expected + 1;
        $changes['updated_at'] = CarbonImmutable::now();

        $affected = EnvelopeRecipient::query()
            ->whereKey($locked->getKey())
            ->where('version', $expected)
            ->update($changes);

        if ($affected !== 1) {
            throw StaleEnvelope::recipient($expected);
        }

        $locked->refresh();
    }

    /**
     * Bring the caller's instance up to date with what was committed.
     *
     * Without this a caller would hold a model describing the state before its own
     * transition, and the next call on it would lose the compare-and-swap against a change
     * it made itself.
     */
    private function syncBack(Envelope|EnvelopeRecipient $caller, Envelope|EnvelopeRecipient $locked): void
    {
        $caller->setRawAttributes($locked->getAttributes(), true);
    }

    /**
     * @throws IllegalTransition
     */
    private function assertLegal(string $transition, Envelope $locked): EnvelopeState
    {
        if (! in_array($locked->state->value, self::TRANSITIONS[$transition], true)) {
            throw IllegalTransition::envelope($transition, $locked->state);
        }

        return $locked->state;
    }

    // ---------------------------------------------------------------------------------
    // Send-time gates
    // ---------------------------------------------------------------------------------

    /**
     * Everything that must be true before anyone is invited, reported all at once.
     *
     * The interesting rule is the last one. A required *material* field can only be
     * completed while content is still open, so a field whose owner will not get a turn
     * before the freeze can never be filled in: in parallel mode nobody can, because the
     * freeze happens at send, and in sequential mode nobody past the first stage can,
     * because the first acceptance freezes it. Sending such an envelope produces a deadlock
     * that only becomes visible once a person is sitting in front of an uncompletable form,
     * so it is refused here instead.
     *
     * @throws SendPreconditionsFailed
     */
    private function assertSendable(Envelope $locked): void
    {
        $problems = [];
        $schema = $locked->fieldSchema();
        $recipients = EnvelopeRecipient::query()
            ->where('envelope_id', $locked->getKey())
            ->get()
            ->keyBy('schema_recipient_id');

        if ($recipients->isEmpty()) {
            $problems[] = [
                'code' => 'no_recipients',
                'message' => 'The envelope has no recipients.',
            ];
        }

        foreach ($recipients as $recipient) {
            if (filter_var($recipient->email, FILTER_VALIDATE_EMAIL) === false) {
                $problems[] = [
                    'code' => 'recipient_missing_email',
                    'recipient' => $recipient->schema_recipient_id,
                    'message' => 'Recipient "'.$recipient->schema_recipient_id.'" has no usable email address.',
                ];
            }

            $required = array_filter(
                $schema->fieldsFor($recipient->schema_recipient_id),
                static fn (FieldDefinition $field): bool => $field->required,
            );

            if ($required === []) {
                $problems[] = [
                    'code' => 'recipient_has_no_required_field',
                    'recipient' => $recipient->schema_recipient_id,
                    'message' => 'Recipient "'.$recipient->schema_recipient_id.'" has no required field to complete, '
                        .'so there is nothing to ask them for.',
                ];
            }
        }

        $stored = $this->storedFieldIds($locked);

        foreach ($schema->fields as $field) {
            if (! $field->required || isset($stored[$field->id])) {
                continue;
            }

            // The service fills this in from the owner's attestation; nobody has to supply it.
            if (FieldMateriality::isServiceSupplied($field->type)) {
                continue;
            }

            if ($field->readOnly) {
                $problems[] = [
                    'code' => 'required_read_only_field_missing_value',
                    'field' => $field->id,
                    'recipient' => $field->recipientId,
                    'message' => 'Required field "'.$field->id.'" is read-only and has no value, so nobody can '
                        .'complete it.',
                ];

                continue;
            }

            if (! FieldMateriality::isMaterial($field->type)) {
                continue;
            }

            $owner = $recipients->get($field->recipientId);
            $ownerActsAfterFreeze = $locked->signing_mode->freezesAtSend()
                || ($owner !== null && $owner->order_index > 1);

            if ($ownerActsAfterFreeze) {
                $problems[] = [
                    'code' => 'required_material_field_unfillable',
                    'field' => $field->id,
                    'recipient' => $field->recipientId,
                    'message' => 'Required field "'.$field->id.'" is part of the agreement\'s material content, '
                        .'but its recipient only acts after the content is frozen. Supply a value before sending.',
                ];
            }
        }

        // docs/HANDOFF.md section 9: reject unusable or absent signing material before
        // inviting signers, and never downgrade the requested level.
        $unavailable = $this->assurance->unavailableReason($locked->assurance_level);

        if ($unavailable !== null) {
            $problems[] = [
                'code' => 'assurance_material_unavailable',
                'message' => $unavailable,
            ];
        }

        if ($problems !== []) {
            throw new SendPreconditionsFailed($problems);
        }
    }

    // ---------------------------------------------------------------------------------
    // Field values
    // ---------------------------------------------------------------------------------

    /**
     * @throws FieldSubmissionRejected
     */
    private function assertRecipientMaySubmit(
        Envelope $locked,
        EnvelopeRecipient $recipient,
        string $fieldId,
    ): FieldDefinition {
        $field = $locked->fieldSchema()->field($fieldId);

        if ($field === null) {
            throw FieldSubmissionRejected::unknownField($fieldId);
        }

        if ($field->recipientId !== $recipient->schema_recipient_id) {
            throw FieldSubmissionRejected::notOwned($fieldId, $field->recipientId);
        }

        if (FieldMateriality::isServiceSupplied($field->type)) {
            throw FieldSubmissionRejected::serviceSupplied($fieldId);
        }

        if ($field->readOnly) {
            throw FieldSubmissionRejected::readOnly($fieldId);
        }

        // Invariant 4. The recipient is `active`, so they have not signed; the only remaining
        // question is whether the field is theirs alone or part of the shared text.
        if ($locked->isContentFrozen() && FieldMateriality::isMaterial($field->type)) {
            throw FieldSubmissionRejected::frozen($fieldId);
        }

        return $field;
    }

    /**
     * @throws RequiredFieldsMissing
     */
    private function assertRequiredFieldsComplete(Envelope $locked, EnvelopeRecipient $recipient): void
    {
        $stored = $this->storedFieldIds($locked);
        $missing = [];

        foreach ($locked->fieldSchema()->fieldsFor($recipient->schema_recipient_id) as $field) {
            if (! $field->required || FieldMateriality::isServiceSupplied($field->type)) {
                continue;
            }

            if (! isset($stored[$field->id])) {
                $missing[] = $field->id;
            }
        }

        if ($missing !== []) {
            throw new RequiredFieldsMissing($recipient->schema_recipient_id, $missing);
        }
    }

    private function writeValue(
        Envelope $locked,
        FieldDefinition $field,
        mixed $value,
        ValueSource $source,
        ?int $recipientId,
    ): void {
        EnvelopeFieldValue::query()->updateOrCreate(
            [
                'envelope_id' => $locked->getKey(),
                'schema_field_id' => $field->id,
            ],
            [
                'recipient_id' => $recipientId,
                'value' => $value,
                'value_sha256' => CanonicalValue::digest($value),
                'set_by' => $source->value,
            ],
        );
    }

    /**
     * Stamp every material value as frozen.
     *
     * Only rows that are not already frozen are touched, so an existing freeze time — which
     * evidence may already refer to — never moves.
     */
    private function stampMaterialValuesFrozen(Envelope $locked, CarbonImmutable $at): void
    {
        $materialFieldIds = FieldMateriality::materialFieldIds($locked->fieldSchema()->fields);

        if ($materialFieldIds === []) {
            return;
        }

        EnvelopeFieldValue::query()
            ->where('envelope_id', $locked->getKey())
            ->whereIn('schema_field_id', $materialFieldIds)
            ->whereNull('frozen_at')
            ->update(['frozen_at' => $at, 'updated_at' => $at]);
    }

    /**
     * @return array<string, true>
     */
    private function storedFieldIds(Envelope $locked): array
    {
        /** @var list<string> $ids */
        $ids = EnvelopeFieldValue::query()
            ->where('envelope_id', $locked->getKey())
            ->pluck('schema_field_id')
            ->all();

        return array_fill_keys($ids, true);
    }

    private function recipientIdFor(Envelope $locked, FieldDefinition $field): ?int
    {
        $id = EnvelopeRecipient::query()
            ->where('envelope_id', $locked->getKey())
            ->where('schema_recipient_id', $field->recipientId)
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    // ---------------------------------------------------------------------------------
    // Acceptance
    // ---------------------------------------------------------------------------------

    private function writeAttestation(
        Envelope $locked,
        EnvelopeRecipient $recipient,
        AcceptanceRequest $request,
        string $materialDigest,
        CarbonImmutable $acceptedAt,
    ): RecipientAttestation {
        $previous = RecipientAttestation::query()
            ->where('envelope_id', $locked->getKey())
            ->orderByDesc('id')
            ->first();

        $acceptedAtString = $acceptedAt->format(AttestationDigest::TIME_FORMAT);

        $digest = AttestationDigest::compute(
            $locked->public_id,
            $recipient->public_id,
            $locked->document_sha256,
            $locked->field_schema_sha256,
            $materialDigest,
            $locked->consent_policy_version,
            $request->sessionRef,
            $acceptedAtString,
            $request->verificationMethod,
            $request->clientEvidence,
            $previous?->attestation_sha256,
        );

        return RecipientAttestation::query()->create([
            'recipient_id' => $recipient->getKey(),
            'envelope_id' => $locked->getKey(),
            'document_sha256' => $locked->document_sha256,
            'field_schema_sha256' => $locked->field_schema_sha256,
            'material_values_sha256' => $materialDigest,
            'consent_policy_version' => $locked->consent_policy_version,
            'session_ref' => $request->sessionRef,
            'accepted_at' => $acceptedAt,
            'verification_method' => $request->verificationMethod,
            'client_evidence' => $request->clientEvidence,
            'prev_attestation_sha256' => $previous?->attestation_sha256,
            'attestation_sha256' => $digest,
        ]);
    }

    /**
     * A repeat of an acceptance that already exists for this session.
     *
     * Returns the original attestation and publishes nothing, because nothing happened
     * (docs/ARCHITECTURE.md invariant 7). The envelope's current state is reported as both
     * `from` and `to` for the same reason.
     *
     * A repeat that quotes a *different* material digest is not a retry — it is a fresh
     * acceptance wearing a used session, and the honest answer is that the review is stale.
     */
    private function replayAcceptance(
        Envelope $locked,
        EnvelopeRecipient $recipient,
        RecipientAttestation $existing,
        AcceptanceRequest $request,
    ): AcceptanceResult {
        if ($existing->consent_policy_version !== $request->consentPolicyVersion) {
            throw new ConsentMismatch($request->consentPolicyVersion, $existing->consent_policy_version);
        }

        if ($existing->material_values_sha256 !== $request->reviewedMaterialSha256) {
            throw StaleReview::materialValuesMoved(
                $request->reviewedMaterialSha256,
                $existing->material_values_sha256,
            );
        }

        $recipient->refresh();

        return new AcceptanceResult(
            $locked,
            $recipient,
            $existing,
            true,
            $locked->state,
            $locked->state,
        );
    }

    // ---------------------------------------------------------------------------------
    // Ordering
    // ---------------------------------------------------------------------------------

    /** Release one signing stage. Returns how many recipients it activated. */
    private function activateStage(Envelope $locked, int $stage, CarbonImmutable $at): int
    {
        return EnvelopeRecipient::query()
            ->where('envelope_id', $locked->getKey())
            ->where('order_index', $stage)
            ->where('state', RecipientState::Pending->value)
            ->increment('version', 1, ['state' => RecipientState::Active->value, 'updated_at' => $at]);
    }

    /** Release everyone at once. Parallel mode only, where there is one stage by construction. */
    private function activateAllPending(Envelope $locked, CarbonImmutable $at): int
    {
        return EnvelopeRecipient::query()
            ->where('envelope_id', $locked->getKey())
            ->where('state', RecipientState::Pending->value)
            ->increment('version', 1, ['state' => RecipientState::Active->value, 'updated_at' => $at]);
    }

    private function firstPendingStage(Envelope $locked): ?int
    {
        $stage = EnvelopeRecipient::query()
            ->where('envelope_id', $locked->getKey())
            ->where('state', RecipientState::Pending->value)
            ->min('order_index');

        return $stage === null ? null : (int) $stage;
    }

    /** Recipients who have neither signed nor declined. */
    private function outstandingRecipients(Envelope $locked): int
    {
        return EnvelopeRecipient::query()
            ->where('envelope_id', $locked->getKey())
            ->whereIn('state', [RecipientState::Pending->value, RecipientState::Active->value])
            ->count();
    }

    private function activeRecipients(Envelope $locked): int
    {
        return EnvelopeRecipient::query()
            ->where('envelope_id', $locked->getKey())
            ->where('state', RecipientState::Active->value)
            ->count();
    }

    /**
     * A stored reason: trimmed, optional, and bounded by the column.
     *
     * Over-long is refused rather than truncated. Silently cutting a cancellation reason in
     * half loses the half that said why.
     */
    private function reason(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }

        $reason = trim($reason);

        if ($reason === '') {
            return null;
        }

        if (mb_strlen($reason) > self::MAX_REASON_LENGTH) {
            throw new InvalidArgumentException(
                'A reason may be at most '.self::MAX_REASON_LENGTH.' characters; '.mb_strlen($reason).' given.',
            );
        }

        return $reason;
    }
}
