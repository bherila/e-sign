<?php

declare(strict_types=1);

namespace App\Domain\Signing\Models;

use App\Domain\Evidence\Sealing\AssuranceLevel;
use App\Domain\Identity\Models\Workspace;
use App\Domain\Preparation\Documents\Models\DocumentRevision;
use App\Domain\Preparation\Schema\FieldSchemaDocument;
use App\Domain\Signing\Envelopes\EnvelopeState;
use App\Domain\Signing\Envelopes\EnvelopeStateMachine;
use App\Domain\Signing\Envelopes\RecipientState;
use App\Domain\Signing\Envelopes\SigningMode;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * query builder and so bypasses it — deliberately, since it never touches these columns and
 * a compare-and-swap must be one statement.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property string $title
 * @property int|null $source_template_version_id
 * @property int $document_revision_id
 * @property string $document_sha256
 * @property array<string, mixed> $field_schema
 * @property string $field_schema_sha256
 * @property array<string, mixed> $render_settings
 * @property string $consent_policy_version
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
 * @property int|null $created_by
 */
class Envelope extends Model
{
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
        'render_settings',
        'consent_policy_version',
        'assurance_level',
        'signing_mode',
        'state',
        'version',
        'expiration_hours',
        'created_by',
    ];

    /** Decoded once per instance; the copied schema cannot change under it. */
    private ?FieldSchemaDocument $decodedSchema = null;

    protected function casts(): array
    {
        return [
            'field_schema' => 'array',
            'render_settings' => 'array',
            'assurance_level' => AssuranceLevel::class,
            'signing_mode' => SigningMode::class,
            'state' => EnvelopeState::class,
            'version' => 'integer',
            'expiration_hours' => 'integer',
            'sent_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'declined_at' => 'immutable_datetime',
            'expired_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'content_frozen_at' => 'immutable_datetime',
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

    /** True once the first acceptance (or send, in parallel mode) froze the content. */
    public function isContentFrozen(): bool
    {
        return $this->content_frozen_at !== null;
    }

    /** The 1-based signing stage currently eligible to act, or null when none is. */
    public function activeStage(): ?int
    {
        $stage = $this->recipients()
            ->where('state', RecipientState::Active->value)
            ->min('order_index');

        return $stage === null ? null : (int) $stage;
    }
}
