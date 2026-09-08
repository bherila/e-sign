<?php

declare(strict_types=1);

namespace App\Domain\Signing\Models;

use App\Domain\Evidence\Retention\LegalHold;
use App\Domain\Evidence\Sealing\AssuranceLevel;
use App\Domain\Identity\Models\Workspace;
use App\Domain\Preparation\Documents\Models\DocumentRevision;
use App\Domain\Preparation\Schema\FieldSchemaDocument;
use App\Domain\Signing\Envelopes\EnvelopeState;
use App\Domain\Signing\Envelopes\EnvelopeStateMachine;
use App\Domain\Signing\Envelopes\SigningMode;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * One agreement in flight.
 *
 * The model is a row plus its invariants; every transition belongs to
 * {@see EnvelopeStateMachine}, which is the single authority
 * named in AGENTS.md ("One state machine"). Nothing here changes `state`, `version`, or any
 * timestamp: a helper that quietly moved a state would be a second state machine.
 *
 * ## Immutability of the snapshot
 *
 * `document_revision_id`, `document_sha256`, `field_schema`, `field_schema_sha256`,
 * `render_settings`, and `consent_policy_version` are copied at creation and refuse to
 * change afterwards. That is invariant 2 in docs/ARCHITECTURE.md expressed where it cannot
 * be forgotten: an acceptance binds to these values, so a code path that edited one would
 * silently move what somebody already agreed to. A correction is a new envelope.
 *
 * The guard is a model event rather than a database trigger, for the portability reason
 * `document_revisions` and `esign_audit_events` give. The state machine writes through the
 * query builder and so bypasses it — deliberately, because a compare-and-swap must be one
 * statement.
 *
 * There is exactly one thing the state machine writes through that door, and it is worth
 * naming: {@see EnvelopeStateMachine::send()} stores the anchor-resolved `field_schema` and
 * its digest in the same statement as the transition to `sent`. That is not an edit of the
 * snapshot, it is its completion. An anchored field arrives carrying a question — "put this
 * box next to the words `Signature:`" — and resolution is where that question becomes a
 * coordinate; it happens before anybody is invited, so nothing has been shown for assent yet
 * and there is nothing an acceptance could already bind to. Afterwards the rule holds without
 * an exception: nothing re-resolves, and a rectangle a signer saw can never move
 * (docs/preparation/anchors.md).
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property string $title
 * @property string|null $source_template_version_id
 * @property int $document_revision_id
 * @property string $document_sha256
 * @property array<string, mixed> $field_schema
 * @property string $field_schema_sha256
 * @property list<array<string, mixed>>|null $omitted_anchor_fields
 * @property array<string, mixed> $render_settings
 * @property string $consent_policy_version
 * @property bool|null $require_otp
 * @property AssuranceLevel $assurance_level
 * @property SigningMode $signing_mode
 * @property EnvelopeState $state
 * @property int $version
 * @property int|null $expiration_hours
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancel_reason
 * @property CarbonImmutable|null $declined_at
 * @property CarbonImmutable|null $expired_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $content_frozen_at
 * @property string|null $artifact_ref
 * @property string|null $finalization_failure_reason
 * @property CarbonImmutable|null $legal_hold_at
 * @property string|null $legal_hold_reason
 * @property string|null $legal_hold_by
 * @property CarbonImmutable|null $deleted_at
 * @property int|null $created_by
 */
class Envelope extends Model
{
    /**
     * Retention soft-deletes an executed envelope; it never hard-deletes one.
     *
     * The trait is here rather than in the Evidence module because the global scope has to
     * apply to every query in the application: once retention has removed an agreement, no
     * listing, no API surface, and no download may keep serving it. The `artifacts` rows it
     * points at stay, and stay immutable, so the digests and the seal identity of what was
     * removed survive as the record that it existed.
     *
     * `App\Domain\Evidence\Retention\RetentionSweeper` is the only writer of `deleted_at`,
     * and `esign:retention:purge-blobs` is the only thing that acts on it afterwards.
     */
    use SoftDeletes;

    /**
     * Columns copied from the source and never changed again.
     *
     * @var list<string>
     */
    public const SNAPSHOT_COLUMNS = [
        'workspace_id',
        'document_revision_id',
        'document_sha256',
        'field_schema',
        'field_schema_sha256',
        'omitted_anchor_fields',
        'render_settings',
        'consent_policy_version',
        'source_template_version_id',
    ];

    protected $fillable = [
        'workspace_id',
        'title',
        'source_template_version_id',
        'document_revision_id',
        'document_sha256',
        'field_schema',
        'field_schema_sha256',
        'omitted_anchor_fields',
        'render_settings',
        'consent_policy_version',
        // Nullable, and null means "inherit the workspace, then the deployment default"
        // rather than false. Deliberately not a snapshot column: how a guest was let in is
        // not part of what they agreed to, and the check actually applied is recorded on the
        // attestation as its verification_method. See
        // App\Domain\Signing\Sessions\OtpRequirement.
        'require_otp',
        'assurance_level',
        'signing_mode',
        'state',
        'version',
        'expiration_hours',
        'created_by',
    ];

    /**
     * Decoded once per instance, and dropped whenever the row underneath is replaced.
     *
     * The cache is safe because the copied schema is immutable for the whole life a signer can
     * see. It has to be invalidated all the same: `send()` writes the anchor-resolved schema
     * through the query builder and then refreshes, and a stale decode would hand the rest of
     * that transition the pre-resolution field set. `setRawAttributes()` is the one door every
     * refresh and every `syncBack()` goes through, so clearing it there covers both.
     */
    private ?FieldSchemaDocument $decodedSchema = null;

    protected function casts(): array
    {
        return [
            'field_schema' => 'array',
            'omitted_anchor_fields' => 'array',
            'render_settings' => 'array',
            'assurance_level' => AssuranceLevel::class,
            'signing_mode' => SigningMode::class,
            'state' => EnvelopeState::class,
            'version' => 'integer',
            'require_otp' => 'boolean',
            'expiration_hours' => 'integer',
            'sent_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'declined_at' => 'immutable_datetime',
            'expired_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'content_frozen_at' => 'immutable_datetime',
            'legal_hold_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $envelope): void {
            if (($envelope->public_id ?? '') === '') {
                $envelope->public_id = (string) Str::ulid();
            }
        });

        static::updating(static function (self $envelope): void {
            foreach (self::SNAPSHOT_COLUMNS as $column) {
                if ($envelope->isDirty($column)) {
                    throw new RuntimeException(
                        'envelopes.'.$column.' is a snapshot taken at creation and cannot change; '
                        .'a material correction is a new envelope with renewed signatures.',
                    );
                }
            }
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function setRawAttributes(array $attributes, $sync = false): static
    {
        $this->decodedSchema = null;

        return parent::setRawAttributes($attributes, $sync);
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<DocumentRevision, $this> */
    public function documentRevision(): BelongsTo
    {
        return $this->belongsTo(DocumentRevision::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<EnvelopeRecipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(EnvelopeRecipient::class)->orderBy('order_index')->orderBy('id');
    }

    /** @return HasMany<EnvelopeFieldValue, $this> */
    public function fieldValues(): HasMany
    {
        return $this->hasMany(EnvelopeFieldValue::class);
    }

    /** @return HasMany<RecipientAttestation, $this> */
    public function attestations(): HasMany
    {
        return $this->hasMany(RecipientAttestation::class)->orderBy('id');
    }

    /**
     * The copied field schema as the Preparation module's value object.
     *
     * Reading it back through `FieldSchemaDocument::fromArray()` re-validates it, which is
     * the point: an envelope whose stored schema no longer imports is a data-integrity
     * failure that should surface loudly, not a document to guess at.
     */
    public function fieldSchema(): FieldSchemaDocument
    {
        return $this->decodedSchema ??= FieldSchemaDocument::fromArray($this->field_schema);
    }

    /**
     * Fields declared in the source but left out because an optional anchor was not in the
     * document.
     *
     * Empty for almost every envelope. When it is not, each entry names the field, its
     * recipient, the anchor text that was looked for, and the reason — see
     * `App\Domain\Preparation\Anchoring\AnchorOmission`. This is what makes the compatibility
     * option observable rather than merely permitted: a reader can tell a field that was
     * intentionally omitted from one that went missing.
     *
     * @return list<array<string, mixed>>
     */
    public function omittedAnchorFields(): array
    {
        $omitted = $this->omitted_anchor_fields;

        return is_array($omitted) ? array_values($omitted) : [];
    }

    public function hasOmittedAnchorFields(): bool
    {
        return $this->omittedAnchorFields() !== [];
    }

    /** True once the first acceptance (or send, in parallel mode) froze the content. */
    public function isContentFrozen(): bool
    {
        return $this->content_frozen_at !== null;
    }

    /**
     * True while the application's own deletion restriction is in place.
     *
     * Not WORM and not a bucket-enforced hold — see
     * {@see LegalHold} for what this does and does not buy.
     * Every deletion path in the Evidence module consults it; nothing else may clear it.
     */
    public function isUnderLegalHold(): bool
    {
        return $this->legal_hold_at !== null;
    }
}
