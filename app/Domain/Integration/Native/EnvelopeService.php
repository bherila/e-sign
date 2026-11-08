<?php

declare(strict_types=1);

namespace App\Domain\Integration\Native;

use App\Domain\Identity\Audit\AuditActor;
use App\Domain\Identity\Audit\AuditRecorder;
use App\Domain\Identity\Credentials\ServiceCredential;
use App\Domain\Identity\Models\Workspace;
use App\Domain\Preparation\Documents\Models\Document;
use App\Domain\Preparation\Schema\RecipientDesignation;
use App\Domain\Preparation\Schema\SchemaVersion;
use App\Domain\Preparation\Templates\Models\Template;
use App\Domain\Preparation\Templates\Models\TemplateVersion;
use App\Domain\Signing\Envelopes\EnvelopeFactory;
use App\Domain\Signing\Envelopes\EnvelopeSourceSnapshot;
use App\Domain\Signing\Envelopes\EnvelopeState;
use App\Domain\Signing\Envelopes\EnvelopeStateMachine;
use App\Domain\Signing\Exceptions\IllegalTransition;
use App\Domain\Signing\Exceptions\InvalidEnvelopeSnapshot;
use App\Domain\Signing\Exceptions\SendPreconditionsFailed;
use App\Domain\Signing\Models\Envelope;
use App\Domain\Signing\Models\EnvelopeRecipient;
use Illuminate\Support\Facades\DB;

/**
 * Everything an HTTP surface needs to do to an envelope, and nothing about HTTP.
 *
 * AGENTS.md: "One state machine. The Firma facade and the native API call the same domain
 * services." This class is where that promise is kept for the envelope family. It owns no
 * signing rules — every transition is a call into
 * {@see EnvelopeStateMachine}, and every creation goes through
 * {@see EnvelopeFactory} — and it owns exactly two things the state machine deliberately
 * does not:
 *
 * 1. **Turning an API request into a snapshot.** A template version or a document plus a
 *    field schema, with the caller's recipient contacts written into the copied schema
 *    before anything is persisted.
 * 2. **Tenancy.** Every lookup is constrained by the principal's workspace *before* the
 *    identifier from the URL is used, so another tenant's id is a 404 and not a 403
 *    (docs/HANDOFF.md section 10). There is no method here that takes an id without a
 *    workspace.
 *
 * A service credential is not a person, so envelopes it creates have `created_by = null`
 * rather than a fabricated user. The credential is recorded in the audit trail instead.
 */
final readonly class EnvelopeService
{
    public function __construct(
        private EnvelopeFactory $factory,
        private EnvelopeStateMachine $machine,
        private AuditRecorder $audit,
    ) {}

    /**
     * Create a draft from a published template version, or from a document plus a schema.
     *
     * The whole call is one transaction: an envelope whose prefills were rejected must not
     * survive as a half-built draft the client never learned about.
     *
     * @throws ApiException|InvalidEnvelopeSnapshot
     */
    public function create(Workspace $workspace, NewEnvelope $input, ?ServiceCredential $credential = null): Envelope
    {
        $snapshot = $input->fromTemplate()
            ? $this->snapshotFromTemplate($workspace, $input)
            : $this->snapshotFromDocument($workspace, $input);

        return DB::transaction(function () use ($workspace, $snapshot, $input, $credential): Envelope {
            $envelope = $this->factory->fromSnapshot($workspace, $snapshot);

            // Not a snapshot column, and deliberately so: the account of *how* somebody was
            // let in is recorded on their attestation, so tightening or relaxing this before
            // anyone has signed changes what will happen and not what did
            // (database/migrations/..._add_require_otp_to_workspaces_and_envelopes.php).
            if ($input->requireOtp !== null) {
                $envelope->require_otp = $input->requireOtp;
            }

            // The signer's buttons (issue #165). A template's values are copied now, so a
            // later template edit cannot change what this agreement offers; a value the
            // caller sent — null included — replaces the template's.
            $template = $input->fromTemplate()
                ? TemplateVersion::query()->where('public_id', $envelope->source_template_version_id)->first()?->template
                : null;

            $envelope->allow_decline = $input->allowDeclineSpecified ? $input->allowDecline : $template?->allow_decline;
            $envelope->show_save_for_later = $input->showSaveForLaterSpecified
                ? $input->showSaveForLater
                : $template?->show_save_for_later;

            if ($envelope->isDirty()) {
                $envelope->save();
            }

            if ($input->values !== []) {
                $this->machine->setSenderValues($envelope, $input->values);
            }

            $this->audit->record(
                $this->actor($credential),
                'integration.native.envelope_created',
                $envelope,
                [
                    'envelope' => $envelope->public_id,
                    'source' => $input->fromTemplate() ? 'template_version' : 'document',
                    'source_template_version_id' => $envelope->source_template_version_id,
                    'require_otp' => $input->requireOtp,
                    'allow_decline' => $envelope->allow_decline,
                    'show_save_for_later' => $envelope->show_save_for_later,
                    'prefilled_fields' => array_keys($input->values),
                ],
            );

            return $this->reload($envelope);
        });
    }

    /**
     * The only supported way to reach an envelope from an API request.
     *
     * @throws ApiException 404 for an id in another workspace, exactly as for one that does
     *                      not exist.
     */
    public function find(Workspace $workspace, string $publicId): Envelope
    {
        $envelope = Envelope::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('public_id', $publicId)
            ->with('recipients')
            ->first();

        if (! $envelope instanceof Envelope) {
            throw ApiException::notFound('envelope');
        }

        return $envelope;
    }

    /**
     * Correct a draft: sender prefills, and the parties' contact details.
     *
     * Prefills go through {@see EnvelopeStateMachine::setSenderValues()}, which is what
     * decides whether a given field is the sender's to write — a read-only field is, a
     * signature never is, and a service-supplied one is nobody's.
     *
     * Contact corrections go through {@see EnvelopeStateMachine::correctRecipients()}, the
     * `update` transition: they rewrite `envelope_recipients.name` and `.email` and the
     * identity snapshot beside them, and they are allowed **only while the envelope is a
     * draft**. After send, an invitation has been addressed and a correction is a different
     * envelope, not an edit — so a non-draft envelope is refused with the same
     * `409 illegal_transition` any other illegal move gets. The early check below answers the
     * ordinary case; the authoritative one is made under the envelope lock, so a correction
     * racing a send cannot rewrite an address after the invitation was scheduled (S-5).
     *
     * The copied field schema is never touched. It is immutable by model guard
     * ({@see Envelope::SNAPSHOT_COLUMNS}) and by design: `schema_recipient_id` is the handle
     * a field uses to find its owner, and rewriting the schema would move fields.
     *
     * @param  array<string, mixed>  $values  Schema field id => value.
     * @param  list<array{id: string, name?: string|null, email?: string|null}>  $recipients
     *
     * @throws ApiException|IllegalTransition
     */
    public function updateDraft(
        Envelope $envelope,
        array $values,
        array $recipients,
        ?ServiceCredential $credential = null,
    ): Envelope {
        if ($envelope->state !== EnvelopeState::Draft) {
            throw IllegalTransition::envelope('update', $envelope->state);
        }

        return DB::transaction(function () use ($envelope, $values, $recipients, $credential): Envelope {
            $changed = $this->correctRecipients($envelope, $recipients);

            if ($values !== []) {
                $this->machine->setSenderValues($envelope, $values);
            }

            if ($changed !== [] || $values !== []) {
                $this->audit->record(
                    $this->actor($credential),
                    'integration.native.envelope_updated',
                    $envelope,
                    [
                        'envelope' => $envelope->public_id,
                        'prefilled_fields' => array_keys($values),
                        'recipients_corrected' => $changed,
                    ],
                );
            }

            return $this->reload($envelope);
        });
    }

    /**
     * @throws SendPreconditionsFailed|IllegalTransition
     */
    public function send(Envelope $envelope): Envelope
    {
        $this->machine->send($envelope);

        return $this->reload($envelope);
    }

    /**
     * @throws IllegalTransition
     */
    public function cancel(Envelope $envelope, ?string $reason): Envelope
    {
        $this->machine->cancel($envelope, $reason);

        return $this->reload($envelope);
    }

    /**
     * @return list<EnvelopeRecipient>
     */
    public function recipients(Envelope $envelope): array
    {
        return array_values($envelope->recipients()->get()->all());
    }

    /* ------------------------------------------------------------------ snapshots */

    /**
     * @throws ApiException|InvalidEnvelopeSnapshot
     */
    private function snapshotFromTemplate(Workspace $workspace, NewEnvelope $input): EnvelopeSourceSnapshot
    {
        // Constrained by workspace first: the subquery decides which versions exist for this
        // caller before the public id is compared, so another tenant's version is absent
        // rather than forbidden.
        $version = TemplateVersion::query()
            ->whereIn('template_id', Template::query()->inWorkspace($workspace)->select('id'))
            ->where('public_id', $input->templateVersionId)
            ->with(['template', 'documentRevision.document'])
            ->first();

        if (! $version instanceof TemplateVersion) {
            throw ApiException::notFound('template version');
        }

        $templateSnapshot = $version->snapshotForEnvelope();
        $templateSnapshot['field_schema'] = $this->withCopies(
            $this->withRecipientContacts($templateSnapshot['field_schema'], $input->recipients),
            $input->copies,
        );

        return EnvelopeSourceSnapshot::fromTemplateVersion($templateSnapshot, $this->overrides($input));
    }

    /**
     * @throws ApiException|InvalidEnvelopeSnapshot
     */
    private function snapshotFromDocument(Workspace $workspace, NewEnvelope $input): EnvelopeSourceSnapshot
    {
        $document = Document::query()
            ->inWorkspace($workspace)
            ->where('public_id', $input->documentId)
            ->first();

        if (! $document instanceof Document) {
            throw ApiException::notFound('document');
        }

        $revision = $document->reviewRevision();

        if ($revision === null) {
            throw ApiException::of(
                ErrorCode::TemplateState,
                'Document '.$document->public_id.' has no review revision yet, so there is nothing to send. '
                .'Upload has to finish preflight before an envelope can be built from it.',
            );
        }

        $schema = $this->withRecipientContacts($input->fieldSchema ?? [], $input->recipients);

        return EnvelopeSourceSnapshot::fromArray(array_replace([
            'title' => $document->title,
            'document_revision_id' => $revision->getKey(),
            'document_sha256' => $revision->sha256,
            'field_schema' => $schema,
            'consent_policy_version' => $input->consentPolicyVersion
                ?? (string) config('esign.templates.default_consent_policy_version'),
        ], $this->overrides($input)));
    }

    /**
     * The decisions that belong to the envelope rather than to its source.
     *
     * `expires_in_hours` is spelled `expiration_hours` inside the Signing module; the
     * translation happens here, once, for the same reason
     * {@see EnvelopeSourceSnapshot::fromTemplateVersion()} translates the template's names.
     *
     * It is also the one property where "absent" and "null" differ: omitting the key takes
     * the default of seven days, and sending `null` means the envelope never expires. The
     * snapshot already draws that distinction, so the flag is carried this far rather than
     * being collapsed into a nullable int that cannot express both.
     *
     * @return array<string, mixed>
     */
    private function overrides(NewEnvelope $input): array
    {
        $overrides = [];

        foreach ([
            'title' => $input->title,
            'assurance_level' => $input->assuranceLevel,
            'signing_mode' => $input->signingMode,
            'consent_policy_version' => $input->consentPolicyVersion,
        ] as $key => $value) {
            if ($value !== null) {
                $overrides[$key] = $value;
            }
        }

        if ($input->expirySpecified) {
            $overrides['expiration_hours'] = $input->expiresInHours;
        }

        return $overrides;
    }

    /**
     * Write the caller's contact details into the schema it is about to copy.
     *
     * The schema owns the recipient *ids*; the API supplies who is behind them for this
     * particular envelope. Doing it before the copy is what makes the envelope's recipients,
     * its identity snapshots, and the schema it was built from agree with each other from
     * the first row written.
     *
     * An id the schema does not declare is refused. Ignoring it would produce an envelope
     * addressed to somebody the caller never intended to leave out.
     *
     * @param  array<string, mixed>  $schema
     * @param  list<array{id: string, name?: string|null, email?: string|null}>  $recipients
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    /**
     * Add cc recipients to a template's parties (issue #169).
     *
     * A cc recipient owns no fields and sits outside `signing_order`, so adding one changes who
     * is sent the completed copy and nothing about what anybody signs. One whose address matches
     * (case-insensitively) a cc recipient the template already declares takes that recipient's
     * place rather than duplicating them, which is upstream's rule for the same request.
     *
     * Only schema 1.2 can say `designation`. A template written against 1.0 or 1.1 is lifted to
     * 1.2 for this envelope's copy when — and only when — a cc recipient is added: every later
     * version keeps every earlier one's meaning, the template's own document is untouched, and
     * an envelope with no added cc keeps the template's version, bytes and digest exactly.
     *
     * @param  array<string, mixed>  $schema
     * @param  list<array{name: string|null, email: string|null}>  $copies
     * @return array<string, mixed>
     */
    private function withCopies(array $schema, array $copies): array
    {
        if ($copies === []) {
            return $schema;
        }

        $recipients = is_array($schema['recipients'] ?? null) ? array_values($schema['recipients']) : [];
        $ids = [];
        $copiedByEmail = [];

        foreach ($recipients as $index => $recipient) {
            $ids[(string) ($recipient['id'] ?? '')] = true;

            if (($recipient['designation'] ?? null) === RecipientDesignation::Cc->value && is_string($recipient['email'] ?? null)) {
                $copiedByEmail[mb_strtolower(trim($recipient['email']))] = $index;
            }
        }

        $next = 1;

        foreach ($copies as $copy) {
            $email = trim((string) ($copy['email'] ?? ''));
            $name = trim((string) ($copy['name'] ?? ''));
            $existing = $copiedByEmail[mb_strtolower($email)] ?? null;

            if ($existing !== null) {
                if ($name !== '') {
                    $recipients[$existing]['name'] = $name;
                }

                continue;
            }

            while (isset($ids['cc_'.$next])) {
                $next++;
            }

            $id = 'cc_'.$next;
            $ids[$id] = true;
            $copiedByEmail[mb_strtolower($email)] = count($recipients);
            $recipients[] = ['id' => $id, 'name' => $name, 'email' => $email, 'designation' => RecipientDesignation::Cc->value];
        }

        $schema['recipients'] = $recipients;

        if (version_compare((string) ($schema['schema_version'] ?? '1.0'), SchemaVersion::CURRENT, '<')) {
            $schema['schema_version'] = SchemaVersion::CURRENT;
        }

        return $schema;
    }

    private function withRecipientContacts(array $schema, array $recipients): array
    {
        if ($recipients === []) {
            return $schema;
        }

        $declared = [];

        foreach (is_array($schema['recipients'] ?? null) ? $schema['recipients'] : [] as $index => $recipient) {
            if (is_array($recipient) && isset($recipient['id']) && is_string($recipient['id'])) {
                $declared[$recipient['id']] = $index;
            }
        }

        $unknown = [];

        foreach ($recipients as $override) {
            $id = $override['id'];

            if (! isset($declared[$id])) {
                $unknown[] = $id;

                continue;
            }

            $index = $declared[$id];

            foreach (['name', 'email'] as $property) {
                $value = $override[$property] ?? null;

                if (is_string($value) && trim($value) !== '') {
                    $schema['recipients'][$index][$property] = trim($value);
                }
            }
        }

        if ($unknown !== []) {
            throw ApiException::of(
                ErrorCode::UnknownRecipient,
                'The field schema does not declare '.(count($unknown) === 1 ? 'a recipient' : 'recipients')
                .' with '.(count($unknown) === 1 ? 'id' : 'ids').' '.implode(', ', $unknown).'.',
                [
                    'unknown_recipient_ids' => $unknown,
                    'declared_recipient_ids' => array_keys($declared),
                ],
            );
        }

        return $schema;
    }

    /**
     * Rewrite the parties' contact details on a draft's recipient rows.
     *
     * @param  list<array{id: string, name?: string|null, email?: string|null}>  $recipients
     * @return list<string> The schema recipient ids that actually changed.
     *
     * @throws ApiException
     */
    private function correctRecipients(Envelope $envelope, array $recipients): array
    {
        if ($recipients === []) {
            return [];
        }

        // Schema recipient ids are part of the envelope's immutable snapshot, so checking them
        // before the lock is sound; the state check and the writes happen under it, in the
        // state machine (S-5).
        $rows = $envelope->recipients()->get()->keyBy('schema_recipient_id');
        $unknown = [];
        $corrections = [];

        foreach ($recipients as $override) {
            $row = $rows->get($override['id']);

            if (! $row instanceof EnvelopeRecipient) {
                $unknown[] = $override['id'];

                continue;
            }

            $corrections[$row->schema_recipient_id] = [
                'name' => isset($override['name']) && trim((string) $override['name']) !== ''
                    ? trim((string) $override['name'])
                    : $row->name,
                'email' => isset($override['email']) && trim((string) $override['email']) !== ''
                    ? trim((string) $override['email'])
                    : $row->email,
            ];
        }

        if ($unknown !== []) {
            throw ApiException::of(
                ErrorCode::UnknownRecipient,
                'This envelope has no recipient with '.(count($unknown) === 1 ? 'id' : 'ids').' '
                .implode(', ', $unknown).'.',
                [
                    'unknown_recipient_ids' => $unknown,
                    'declared_recipient_ids' => array_values($rows->keys()->all()),
                ],
            );
        }

        return $this->machine->correctRecipients($envelope, $corrections);
    }

    private function reload(Envelope $envelope): Envelope
    {
        return $envelope->refresh()->load('recipients');
    }

    private function actor(?ServiceCredential $credential): AuditActor
    {
        return $credential === null
            ? AuditActor::system('integration.native')
            : AuditActor::system('integration.native credential '.$credential->prefix);
    }
}
