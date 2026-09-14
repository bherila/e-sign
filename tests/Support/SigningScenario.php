<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Identity\Models\Workspace;
use App\Domain\Preparation\Documents\DocumentStorageKey;
use App\Domain\Preparation\Documents\Models\Document;
use App\Domain\Preparation\Documents\Models\DocumentRevision;
use App\Domain\Preparation\Documents\RevisionKind;
use App\Domain\Signing\Contracts\AnchorResolution;
use App\Domain\Signing\Contracts\AssurancePolicyCheck;
use App\Domain\Signing\Contracts\EnvelopeEventSink;
use App\Domain\Signing\Envelopes\AcceptanceRequest;
use App\Domain\Signing\Envelopes\AcceptanceResult;
use App\Domain\Signing\Envelopes\EnvelopeFactory;
use App\Domain\Signing\Envelopes\EnvelopeSourceSnapshot;
use App\Domain\Signing\Envelopes\EnvelopeStateMachine;
use App\Domain\Signing\Envelopes\VerificationMethod;
use App\Domain\Signing\Fields\FieldMateriality;
use App\Domain\Signing\Models\Envelope;
use App\Domain\Signing\Models\EnvelopeRecipient;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Builds the rows a signing test needs, and nothing it does not.
 *
 * Rows only: the document revision has a plausible key and digest with no bytes behind it,
 * the same trade-off `DocumentFactory` documents. The state machine never opens a PDF, so a
 * real one here would only slow every test down. Anything that needs actual bytes belongs in
 * the Preparation or Evidence suites.
 *
 * All data is synthetic (AGENTS.md).
 */
final class SigningScenario
{
    public readonly Workspace $workspace;

    public readonly User $user;

    public readonly Document $document;

    public readonly DocumentRevision $revision;

    public readonly FakeAssurancePolicyCheck $assurance;

    public readonly RecordingEnvelopeEventSink $sink;

    private function __construct(
        ?FakeAssurancePolicyCheck $assurance,
        ?RecordingEnvelopeEventSink $sink,
        ?string $revisionBytes,
    ) {
        $this->workspace = Workspace::factory()->create();
        $this->user = User::factory()->create();
        $this->document = Document::factory()->for($this->workspace)->create();
        $this->revision = $this->createReviewRevision($revisionBytes);
        $this->assurance = $assurance ?? FakeAssurancePolicyCheck::available();
        $this->sink = $sink ?? new RecordingEnvelopeEventSink;
    }

    /**
     * @param  string|null  $revisionBytes  Real PDF bytes to store behind the review revision.
     *                                      Null keeps the row-only revision described above,
     *                                      which is what a state-machine test wants; the
     *                                      finalization suite passes a fixture because it
     *                                      actually renders the document.
     */
    public static function create(
        ?FakeAssurancePolicyCheck $assurance = null,
        ?RecordingEnvelopeEventSink $sink = null,
        ?string $revisionBytes = null,
    ): self {
        return new self($assurance, $sink, $revisionBytes);
    }

    /**
     * Bind this scenario's doubles into the container so a resolved state machine uses them.
     *
     * Resolving through the container rather than calling `new` keeps the service provider's
     * wiring on the tested path; only the two ports are swapped.
     */
    public function bind(): self
    {
        app()->instance(AssurancePolicyCheck::class, $this->assurance);
        app()->instance(EnvelopeEventSink::class, $this->sink);

        return $this;
    }

    public function machine(): EnvelopeStateMachine
    {
        return new EnvelopeStateMachine($this->sink, $this->assurance, app(AnchorResolution::class));
    }

    public function factory(): EnvelopeFactory
    {
        return new EnvelopeFactory($this->sink);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function snapshotArray(array $overrides = []): array
    {
        return array_replace([
            'title' => 'Synthetic mutual NDA',
            'document_revision_id' => $this->revision->getKey(),
            'document_sha256' => $this->revision->sha256,
            'field_schema' => SigningFixtures::sequentialTwoSigners(),
            'consent_policy_version' => SigningFixtures::CONSENT_VERSION,
            'render_settings' => ['signature_style' => 'typed_or_drawn'],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function snapshot(array $overrides = []): EnvelopeSourceSnapshot
    {
        return EnvelopeSourceSnapshot::fromArray($this->snapshotArray($overrides));
    }

    /**
     * A draft envelope built through the real factory.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function draft(array $overrides = []): Envelope
    {
        return $this->factory()->fromSnapshot($this->workspace, $this->snapshot($overrides), $this->user);
    }

    /**
     * A draft with every sender-supplied value already in place, so `send()` passes its gate.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function preparedDraft(array $overrides = []): Envelope
    {
        $envelope = $this->draft($overrides);
        $prefills = SigningFixtures::senderPrefills($envelope);

        if ($prefills !== []) {
            $this->machine()->setSenderValues($envelope, $prefills);
        }

        return $envelope->refresh();
    }

    /**
     * A sent envelope with the first stage released.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function sent(array $overrides = []): Envelope
    {
        $envelope = $this->preparedDraft($overrides);
        $this->machine()->send($envelope);

        return $envelope->refresh();
    }

    /**
     * Submit everything the recipient is required to supply themselves.
     *
     * Read-only and service-supplied fields are excluded, because they are not theirs to
     * complete — which is the same rule `accept()` applies when it checks for missing values.
     */
    public function completeRequiredFieldsFor(Envelope $envelope, EnvelopeRecipient $recipient): void
    {
        $values = [];

        foreach ($envelope->fieldSchema()->fieldsFor($recipient->schema_recipient_id) as $field) {
            if (! $field->required || $field->readOnly || FieldMateriality::isServiceSupplied($field->type)) {
                continue;
            }

            $values[$field->id] = SigningFixtures::sampleValue($field);
        }

        if ($values !== []) {
            $this->machine()->submitValues($recipient->refresh(), $values);
        }
    }

    /**
     * An acceptance request quoting what the envelope holds right now.
     *
     * Tests that care about staleness build their own with deliberately older values.
     */
    public function acceptanceRequest(
        Envelope $envelope,
        string $sessionRef = 'session-1',
        ?string $reviewedMaterial = null,
        ?int $reviewedVersion = null,
        ?string $consentPolicyVersion = null,
    ): AcceptanceRequest {
        $envelope->refresh();

        return new AcceptanceRequest(
            consentPolicyVersion: $consentPolicyVersion ?? $envelope->consent_policy_version,
            sessionRef: $sessionRef,
            reviewedMaterialSha256: $reviewedMaterial ?? $this->machine()->materialValuesDigest($envelope),
            reviewedEnvelopeVersion: $reviewedVersion ?? $envelope->version,
            verificationMethod: VerificationMethod::EmailLink,
            clientEvidence: ['ip' => '198.51.100.7', 'user_agent' => 'SyntheticBrowser/1.0'],
        );
    }

    /** Complete the recipient's own required fields, then accept. */
    public function signAs(
        Envelope $envelope,
        EnvelopeRecipient $recipient,
        string $sessionRef = 'session-1',
    ): AcceptanceResult {
        $this->completeRequiredFieldsFor($envelope, $recipient);

        return $this->machine()->accept($recipient->refresh(), $this->acceptanceRequest($envelope, $sessionRef));
    }

    public function recipient(Envelope $envelope, string $schemaRecipientId): EnvelopeRecipient
    {
        return EnvelopeRecipient::query()
            ->where('envelope_id', $envelope->getKey())
            ->where('schema_recipient_id', $schemaRecipientId)
            ->firstOrFail();
    }

    private function createReviewRevision(?string $bytes): DocumentRevision
    {
        $sha256 = $bytes === null
            ? hash('sha256', 'synthetic-review-revision-'.Str::ulid())
            : hash('sha256', $bytes);

        $path = DocumentStorageKey::for(
            $this->workspace->public_id,
            $this->document->public_id,
            RevisionKind::Review,
            $sha256,
        )->value;

        if ($bytes !== null) {
            Storage::disk('documents')->put($path, $bytes);
        }

        return DocumentRevision::query()->create([
            'document_id' => $this->document->getKey(),
            'kind' => RevisionKind::Review,
            'disk' => 'documents',
            'path' => $path,
            'sha256' => $sha256,
            'bytes' => $bytes === null ? 2_048 : strlen($bytes),
            'page_count' => $bytes === null ? 2 : null,
            'normalization' => ['applied' => false],
            'created_by' => $this->user->getKey(),
        ]);
    }
}
