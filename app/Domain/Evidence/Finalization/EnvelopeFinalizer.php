<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Finalization;

use App\Domain\Delivery\Webhooks\TextRedactor;
use App\Domain\Evidence\Contracts\ArtifactValidator;
use App\Domain\Evidence\Contracts\PdfSealer;
use App\Domain\Evidence\Contracts\SealIdentity;
use App\Domain\Evidence\Finalization\Artifacts\Artifact;
use App\Domain\Evidence\Finalization\Artifacts\ArtifactKind;
use App\Domain\Evidence\Finalization\Artifacts\ArtifactStore;
use App\Domain\Evidence\Finalization\Exceptions\FinalizationException;
use App\Domain\Evidence\Finalization\Exceptions\PublicationSuperseded;
use App\Domain\Evidence\Sealing\SealRequest;
use App\Domain\Preparation\Documents\DocumentBlobStore;
use App\Domain\Preparation\Documents\Models\DocumentRevision;
use App\Domain\Preparation\Schema\FieldSchemaDocument;
use App\Domain\Signing\Envelopes\EnvelopeState;
use App\Domain\Signing\Envelopes\EnvelopeStateMachine;
use App\Domain\Signing\Exceptions\SigningException;
use App\Domain\Signing\Models\Envelope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The staged artifact publication of docs/ARCHITECTURE.md, in four steps and in one place.
 *
 * There is no cross-store transaction to be had: the database and the object store cannot
 * commit together, and pretending otherwise is how a system ends up with completed envelopes
 * whose PDF is missing, or with orphaned bytes nobody can account for. So the sequence is
 * arranged so that every possible crash point leaves a state that is either correct or
 * visibly incomplete, and never one that looks finished but is not.
 *
 * ```
 * 1. lock envelope ─ require finalizing ─ allocate generation ─ capture immutable input ─ run(started)
 *                                          (transaction commits; the lock is released)
 * 2. render ─ seal ─ validate ─ hash ─ upload ─ READ BACK and compare digests ─ run(rendered→uploaded)
 *                                          (no lock held; this is the slow part)
 * 3. lock envelope ─ re-check state, generation, and that nothing is published ─ insert artifact rows
 *    ─ markCompleted() ─ run(published)      (one transaction; the completion event is inside it)
 *
 * 4. any failure after step 1 ─ run(failed, redacted) ─ markFinalizationFailed() ─ publish nothing
 * ```
 *
 * ## Why each step is where it is
 *
 * **The input is captured under the lock (step 1)** so that everything rendered afterwards
 * describes one consistent instant. Re-reading a field value mid-render would let a
 * concurrent edit change the document between the seal and the publication.
 *
 * **The work happens outside the lock (step 2)** because sealing can involve a network round
 * trip to a timestamp authority, and holding a row lock across it would block every other
 * transition on the envelope — including the cancel that ought to be able to win the race.
 *
 * **Publication re-checks under a new lock (step 3).** By then the world may have moved:
 * another attempt may have published, or the sender may have cancelled. All three checks —
 * state, generation, and no existing artifact — are compare-and-swap guards, and losing one
 * is a correct outcome rather than an error (docs/ARCHITECTURE.md invariant 6). That is why
 * {@see PublicationSuperseded} is handled separately from a real failure: a superseded
 * attempt must not push a completed or cancelled envelope into `finalization_failed`.
 *
 * **Nothing is published until the bytes have been read back.** `ArtifactStore::putVerified()`
 * re-reads through the storage adapter and re-hashes; only then does step 3 run, and only
 * step 3 calls `markCompleted()`. That ordering is docs/ARCHITECTURE.md invariant 5 —
 * "a completed envelope has a durable, validated final PDF" — expressed as control flow.
 *
 * ## Recovery
 *
 * A worker that dies between the upload and the publication leaves a run in `uploaded` with
 * its outputs recorded. The retry (`retryFinalization()` then re-dispatch) finds it, confirms
 * the bytes are still there and still hash to what was recorded, and publishes *those* bytes.
 * It does not re-render: re-sealing would burn a second timestamp, produce a second set of
 * objects, and give the agreement a different digest from the one the crashed attempt had
 * already proved durable.
 */
final readonly class EnvelopeFinalizer
{
    public function __construct(
        private EnvelopeStateMachine $stateMachine,
        private PdfSealer $sealer,
        private ArtifactValidator $validator,
        private SealIdentity $sealIdentity,
        private ExecutedDocumentRenderer $renderer,
        private CompletionReportDocument $completionReport,
        private ArtifactStore $store,
        private DocumentBlobStore $documents,
        private TextRedactor $redactor,
        private string $disk,
    ) {}

    /**
     * Run one finalization attempt to completion, or fail closed.
     *
     * @throws FinalizationException
     */
    public function finalize(Envelope $envelope): FinalizationRun
    {
        [$run, $input] = $this->begin($envelope);

        try {
            $prepared = $this->prepare($run, $input);

            return $this->publish($run, $prepared);
        } catch (PublicationSuperseded $superseded) {
            // A race resolved against this attempt. The envelope is in the state somebody
            // else legitimately put it in, so it is left exactly as it is.
            $this->recordFailure($run, $superseded);

            return $run->refresh();
        } catch (Throwable $failure) {
            $this->recordFailure($run, $failure);
            $this->markFailed($envelope, $failure);

            throw $failure instanceof FinalizationException
                ? $failure
                : new FinalizationException(
                    'Finalization failed: '.$this->redact($failure->getMessage()),
                    previous: $failure,
                );
        }
    }

    // -------------------------------------------------------------------------------------
    // Step 1 — under the envelope lock
    // -------------------------------------------------------------------------------------

    /**
     * @return array{FinalizationRun, FinalizationInput}
     */
    private function begin(Envelope $envelope): array
    {
        return DB::transaction(function () use ($envelope): array {
            $locked = Envelope::query()->whereKey($envelope->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                throw (new ModelNotFoundException)->setModel(Envelope::class, [$envelope->getKey()]);
            }

            if ($locked->state !== EnvelopeState::Finalizing) {
                // Deliberately before any run row is written: an envelope that is not
                // finalizing has not asked for this, and recording an attempt against it
                // would invent history.
                throw new FinalizationException(
                    'Finalization was requested for an envelope in state "'.$locked->state->value.'". '
                    .'Only a finalizing envelope can be finalized; nothing was attempted.',
                );
            }

            $generation = 1 + (int) FinalizationRun::query()
                ->where('envelope_id', $locked->getKey())
                ->max('generation');

            $input = FinalizationInput::capture($locked);

            $run = FinalizationRun::query()->create([
                'envelope_id' => $locked->getKey(),
                'generation' => $generation,
                'state' => FinalizationRunState::Started->value,
                'input_snapshot' => $input->toArray(),
                'started_at' => CarbonImmutable::now(),
            ]);

            return [$run, $input];
        });
    }

    // -------------------------------------------------------------------------------------
    // Step 2 — outside the lock
    // -------------------------------------------------------------------------------------

    /**
     * @return list<PreparedArtifact>
     */
    private function prepare(FinalizationRun $run, FinalizationInput $input): array
    {
        $reused = $this->reusableArtifacts($run, $input);

        if ($reused !== []) {
            $run->update([
                'state' => FinalizationRunState::Uploaded->value,
                'outputs' => array_map(static fn (PreparedArtifact $a): array => $a->toArray(), $reused),
            ]);

            return $reused;
        }

        $prepared = $this->render($input, $run->generation);

        $run->update(['state' => FinalizationRunState::Rendered->value]);

        foreach ($prepared as $artifact) {
            $this->store->putVerified($artifact->disk, $artifact->key, $artifact->bytes, $artifact->sha256);
        }

        $run->update([
            'state' => FinalizationRunState::Uploaded->value,
            'outputs' => array_map(static fn (PreparedArtifact $a): array => $a->toArray(), $prepared),
        ]);

        return $prepared;
    }

    /**
     * Bytes an earlier attempt already proved durable, or `[]`.
     *
     * Two things have to hold, and both are checked rather than assumed: the earlier run was
     * rendering the same immutable inputs (equal snapshot digests), and every object it
     * uploaded is still present and still hashes to what was recorded. A stored object that
     * has drifted is not reused — the attempt renders again instead, which is slower and
     * correct.
     *
     * @return list<PreparedArtifact>
     */
    private function reusableArtifacts(FinalizationRun $run, FinalizationInput $input): array
    {
        $candidates = FinalizationRun::query()
            ->where('envelope_id', $run->envelope_id)
            ->where('id', '!=', $run->getKey())
            ->whereNotNull('outputs')
            ->orderByDesc('generation')
            ->get();

        foreach ($candidates as $candidate) {
            if (! $candidate->canSupplyBytesFor($input)) {
                continue;
            }

            try {
                $prepared = array_values(array_map(
                    static fn (array $output): PreparedArtifact => PreparedArtifact::fromRunOutput(
                        $output,
                        $input->workspacePublicId,
                        $input->envelopePublicId,
                    ),
                    $candidate->outputs ?? [],
                ));

                if (count($prepared) !== count(ArtifactKind::cases())) {
                    continue;
                }

                foreach ($prepared as $artifact) {
                    $digest = $this->store->digestOf($artifact->disk, $artifact->key->value);

                    if (! hash_equals($artifact->sha256, $digest)) {
                        continue 2;
                    }
                }

                return $prepared;
            } catch (Throwable) {
                // An unusable record of an earlier attempt is not a reason to fail this one.
                continue;
            }
        }

        return [];
    }

    /**
     * Render, seal, validate, and hash. Nothing here touches the database or the object store.
     *
     * @return list<PreparedArtifact>
     */
    private function render(FinalizationInput $input, int $generation): array
    {
        $reviewPdf = $this->reviewBytes($input);

        $report = CompletionReport::build(
            $input,
            $this->sealIdentity->keyId(),
            $this->sealIdentity->certificateFingerprint(),
        );

        $reportPdf = $this->completionReport->render($report);

        $executed = $this->renderer->render(
            $input,
            $reviewPdf,
            $reportPdf,
            $this->schemaFor($input),
        );

        // Fail closed on any sealing exception: the level requested is the level the bytes
        // must reach, and a sealer that cannot reach it throws rather than downgrading.
        $sealed = $this->sealer->seal(new SealRequest(
            pdf: $executed,
            level: $input->assuranceLevel,
            reason: 'Executed agreement sealed by the service',
            location: 'BWH eSign',
        ));

        $sealedAt = CarbonImmutable::now()->utc()->toIso8601ZuluString();

        // Re-read the produced bytes independently of the sealer's own check. "The backend
        // was asked for profile X" is not evidence that the bytes reach profile X.
        $validation = $this->validator->validate($sealed->pdf);

        if (! $validation->isValid() || $validation->reachedLevel() !== $input->assuranceLevel) {
            throw new FinalizationException(
                'The sealed document did not validate at PAdES '.$input->assuranceLevel->value
                .' when it was read back (reported '.($validation->reachedLevel()?->value ?? 'unsigned')
                .'). Nothing is published.',
            );
        }

        $validationArray = EvidenceDocument::validationReport($validation);

        $executedArtifact = PreparedArtifact::of(
            kind: ArtifactKind::ExecutedPdf,
            bytes: $sealed->pdf,
            disk: $this->disk,
            workspacePublicId: $input->workspacePublicId,
            envelopePublicId: $input->envelopePublicId,
            sealKeyId: $sealed->keyId,
            sealCertificateSha256: $this->sealIdentity->certificateFingerprint(),
            assuranceLevelReached: $sealed->level,
            validationReport: $validationArray,
        );

        $reportArtifact = PreparedArtifact::of(
            kind: ArtifactKind::CompletionReport,
            bytes: $reportPdf,
            disk: $this->disk,
            workspacePublicId: $input->workspacePublicId,
            envelopePublicId: $input->envelopePublicId,
            sealKeyId: $sealed->keyId,
            sealCertificateSha256: $this->sealIdentity->certificateFingerprint(),
        );

        // Built last, because it is the document that names the other two by digest — and
        // its own digest is deliberately not in it.
        $evidenceJson = EvidenceDocument::build(
            input: $input,
            generation: $generation,
            sealKeyId: $sealed->keyId,
            sealCertificateSha256: $this->sealIdentity->certificateFingerprint(),
            sealSubject: $this->sealIdentity->subject(),
            sealDigestAlgorithm: $sealed->digestAlgorithm,
            timestampAuthority: $sealed->timestampAuthority,
            requested: $input->assuranceLevel,
            reached: $sealed->level,
            validation: $validation,
            sealedAt: $sealedAt,
            artifactDigests: [
                ArtifactKind::ExecutedPdf->value => $executedArtifact->sha256,
                ArtifactKind::CompletionReport->value => $reportArtifact->sha256,
            ],
            artifactSizes: [
                ArtifactKind::ExecutedPdf->value => $executedArtifact->byteCount,
                ArtifactKind::CompletionReport->value => $reportArtifact->byteCount,
            ],
        );

        $evidenceArtifact = PreparedArtifact::of(
            kind: ArtifactKind::EvidenceJson,
            bytes: $evidenceJson,
            disk: $this->disk,
            workspacePublicId: $input->workspacePublicId,
            envelopePublicId: $input->envelopePublicId,
            sealKeyId: $sealed->keyId,
            sealCertificateSha256: $this->sealIdentity->certificateFingerprint(),
        );

        return [$executedArtifact, $reportArtifact, $evidenceArtifact];
    }

    /**
     * The reviewed revision's bytes, checked against the digest the envelope froze.
     *
     * Checked rather than trusted, because everything downstream — the attestations, the
     * evidence document, the seal — asserts that the executed document was built from
     * exactly these bytes.
     */
    private function reviewBytes(FinalizationInput $input): string
    {
        $revision = DocumentRevision::query()->findOrFail($input->documentRevisionId);

        $bytes = $this->documents->disk($revision->disk)->get($revision->path);

        if (! is_string($bytes) || $bytes === '') {
            throw new FinalizationException(
                'The reviewed document revision could not be read from storage, so there is nothing '
                .'to execute. Nothing is published.',
            );
        }

        if (! hash_equals($input->documentSha256, hash('sha256', $bytes))) {
            throw new FinalizationException(
                'The reviewed document revision no longer hashes to the digest the envelope and every '
                .'attestation are bound to. Nothing is published.',
            );
        }

        return $bytes;
    }

    private function schemaFor(FinalizationInput $input): FieldSchemaDocument
    {
        return Envelope::query()
            ->where('public_id', $input->envelopePublicId)
            ->firstOrFail()
            ->fieldSchema();
    }

    // -------------------------------------------------------------------------------------
    // Step 3 — a new transaction
    // -------------------------------------------------------------------------------------

    /**
     * @param  list<PreparedArtifact>  $prepared
     */
    private function publish(FinalizationRun $run, array $prepared): FinalizationRun
    {
        return DB::transaction(function () use ($run, $prepared): FinalizationRun {
            $locked = Envelope::query()->whereKey($run->envelope_id)->lockForUpdate()->first();

            if ($locked === null) {
                throw (new ModelNotFoundException)->setModel(Envelope::class, [$run->envelope_id]);
            }

            if ($locked->state !== EnvelopeState::Finalizing) {
                throw new PublicationSuperseded(
                    'The envelope moved to "'.$locked->state->value.'" while this attempt was rendering, '
                    .'so it publishes nothing.',
                );
            }

            $latest = (int) FinalizationRun::query()->where('envelope_id', $run->envelope_id)->max('generation');

            if ($latest !== $run->generation) {
                throw new PublicationSuperseded(
                    'A newer finalization attempt (generation '.$latest.') has started, so generation '
                    .$run->generation.' publishes nothing.',
                );
            }

            if (Artifact::query()->where('envelope_id', $run->envelope_id)->exists()) {
                throw new PublicationSuperseded(
                    'This envelope already has published artifacts, so nothing further is published. '
                    .'Completed evidence is never replaced.',
                );
            }

            $executedRef = '';

            foreach ($prepared as $artifact) {
                $row = Artifact::query()->create([
                    ...$artifact->rowAttributes((int) $run->envelope_id, $run->generation),
                    'published_at' => CarbonImmutable::now(),
                ]);

                if ($artifact->kind === ArtifactKind::ExecutedPdf) {
                    $executedRef = $row->public_id;
                }
            }

            if ($executedRef === '') {
                throw new FinalizationException(
                    'No executed PDF was prepared, so there is nothing for the completion to refer to.',
                );
            }

            // Publishes `signing_request.completed` through the sink inside this transaction,
            // so the state change and its event are one atomic fact.
            $this->stateMachine->markCompleted($locked, $executedRef, $locked->version);

            $run->update([
                'state' => FinalizationRunState::Published->value,
                'error' => null,
                'finished_at' => CarbonImmutable::now(),
            ]);

            return $run;
        });
    }

    // -------------------------------------------------------------------------------------
    // Step 4 — failure
    // -------------------------------------------------------------------------------------

    private function recordFailure(FinalizationRun $run, Throwable $failure): void
    {
        $run->update([
            'state' => FinalizationRunState::Failed->value,
            'error' => $this->redact($failure->getMessage()),
            'finished_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * Put the envelope into `finalization_failed`, if it is still somewhere that can go there.
     *
     * An illegal transition here is expected rather than exceptional: by the time a failure is
     * being recorded the envelope may have been cancelled or completed by another attempt, and
     * in both cases the state it is in is the right one. What must never happen is a failure
     * being silently swallowed into `completed`, and that cannot happen because only
     * {@see publish()} calls `markCompleted()`.
     */
    private function markFailed(Envelope $envelope, Throwable $failure): void
    {
        try {
            $envelope->refresh();

            $this->stateMachine->markFinalizationFailed(
                $envelope,
                $this->redact($failure->getMessage()),
                $envelope->version,
            );
        } catch (SigningException|ModelNotFoundException) {
            // The envelope is no longer finalizing. Its current state is the legal outcome of
            // whatever else happened; the run row records why this attempt did not publish.
        }
    }

    /**
     * A failure message fit to store: no credentials, no long opaque runs, and bounded.
     *
     * Storage keys carry digests and a sealing error can name a certificate subject or a TSA
     * endpoint, so the same redaction the delivery modules apply to their attempt logs applies
     * here (docs/HANDOFF.md section 8, minimized evidence).
     */
    private function redact(string $message): string
    {
        return $this->redactor->redact($message, [], FinalizationRun::MAX_ERROR_LENGTH);
    }
}
