<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Templates\Models;

use App\Domain\Preparation\Documents\Models\DocumentRevision;
use App\Domain\Preparation\Schema\FieldSchemaDocument;
use App\Domain\Preparation\Templates\PublishedVersionIsImmutableException;
use App\Domain\Preparation\Templates\RenderSettings;
use App\Domain\Preparation\Templates\TemplateStateException;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One snapshot of a template: the thing a sender sends.
 *
 * It captures everything docs/HANDOFF.md section 6 requires — the review revision of the
 * PDF, the recipients/roles, the field definitions, the consent policy version, and the
 * rendering settings — so that sending can copy values rather than follow references. That
 * is what makes "later template changes never mutate existing requests" true by
 * construction instead of by discipline.
 *
 * ## Immutable after publish
 *
 * Before `published_at` the row is a draft and the sender may edit it freely. Setting
 * `published_at` is the last permitted write: from then on the model refuses updates and
 * deletes with {@see PublishedVersionIsImmutableException}. Enforcement is in the model, not
 * a trigger, for the portability reason `document_revisions` gives — a trigger does not
 * behave identically on SQLite, MySQL, and MariaDB. Editing a template after publishing
 * means a new version, never an edit of the published one.
 *
 * ## Reading the field schema
 *
 * Always read it through {@see canonicalFieldSchemaJson()}, never by hashing the raw column.
 * A MySQL `JSON` column does not preserve object key order, so the bytes that come back are
 * not necessarily the bytes that went in. Re-importing the decoded value through
 * {@see FieldSchemaDocument} is order-insensitive and reproduces the canonical bytes — and
 * therefore the stored digest — on every supported engine.
 *
 * @property int $id
 * @property string $public_id
 * @property int $template_id
 * @property int $version
 * @property int $document_revision_id
 * @property array<string, mixed> $field_schema
 * @property string $field_schema_sha256
 * @property list<array<string, mixed>> $recipients
 * @property string $consent_policy_version
 * @property array<string, mixed> $render_settings
 * @property CarbonInterface|null $published_at
 * @property int|null $created_by
 */
class TemplateVersion extends Model
{
    /** Written once, then stamped with `published_at`. There is no updated_at column. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'template_id',
        'version',
        'document_revision_id',
        'field_schema',
        'field_schema_sha256',
        'recipients',
        'consent_policy_version',
        'render_settings',
        'published_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'field_schema' => 'array',
            'recipients' => 'array',
            'render_settings' => 'array',
            'published_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $version): void {
            if (($version->public_id ?? '') === '') {
                $version->public_id = (string) Str::ulid();
            }
        });

        // `getOriginal()` is the value as it stands in the database, so the publish write
        // itself is allowed (it is still null at that point) and every later write is not.
        static::updating(static function (self $version): void {
            if ($version->getOriginal('published_at') !== null) {
                throw new PublishedVersionIsImmutableException($version->public_id);
            }
        });

        static::deleting(static function (self $version): void {
            if ($version->getOriginal('published_at') !== null) {
                throw new PublishedVersionIsImmutableException(
                    $version->public_id,
                    'A published template version cannot be deleted. Retire the template instead; '
                    .'envelopes sent from this version keep their own snapshot either way.',
                );
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<Template, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
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

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    public function isDraft(): bool
    {
        return $this->published_at === null;
    }

    /**
     * @param  Builder<TemplateVersion>  $query
     * @return Builder<TemplateVersion>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at');
    }

    /** The stored field set as a value object. */
    public function fieldSchemaDocument(): FieldSchemaDocument
    {
        return FieldSchemaDocument::fromArray($this->field_schema);
    }

    /**
     * The canonical bytes of the stored field set: the export contract, and the only thing
     * `field_schema_sha256` is ever the digest of.
     */
    public function canonicalFieldSchemaJson(): string
    {
        return $this->fieldSchemaDocument()->canonicalJson();
    }

    /** Whether the stored schema still hashes to the digest recorded when it was written. */
    public function fieldSchemaDigestMatches(): bool
    {
        return hash('sha256', $this->canonicalFieldSchemaJson()) === $this->field_schema_sha256;
    }

    public function renderSettings(): RenderSettings
    {
        return RenderSettings::fromArray($this->render_settings);
    }

    /**
     * Everything sending copies into an envelope.
     *
     * The Signing module calls this instead of reading columns, so what a version means is
     * defined in one place and a new snapshot property is added here rather than in every
     * consumer. Values are returned as they are stored, canonicalised where a canonical form
     * exists.
     *
     * Two guards, both fail-closed:
     *
     *  - **A draft is refused.** A draft can still change, so an envelope that copied one
     *    would be a request whose template edits *did* reach it — the exact thing
     *    docs/HANDOFF.md section 6 forbids.
     *  - **The digest is re-verified.** If the stored schema no longer hashes to the digest
     *    written with it, something has rewritten the row or an engine has normalised it,
     *    and the honest answer is an error rather than a snapshot nobody can reproduce.
     *
     * `document_revision_id` is the internal key, because it is what the envelope's own
     * foreign key binds to; `document_revision_public_id` is the identifier that may appear
     * in a payload. No disk name and no object path is exposed here or anywhere else.
     *
     * @return array{
     *     template_id: string,
     *     template_name: string,
     *     template_version_id: string,
     *     version: int,
     *     document_id: string|null,
     *     document_revision_id: int,
     *     document_revision_public_id: string|null,
     *     document_revision_sha256: string|null,
     *     document_page_count: int|null,
     *     field_schema: array<string, mixed>,
     *     field_schema_sha256: string,
     *     recipients: list<array<string, mixed>>,
     *     consent_policy_version: string,
     *     render_settings: array<string, mixed>,
     *     published_at: string,
     * }
     *
     * @throws TemplateStateException When the version is a draft, or its schema digest no
     *                                longer matches what was stored.
     */
    public function snapshotForEnvelope(): array
    {
        if (! $this->isPublished()) {
            throw TemplateStateException::versionNotPublished($this->public_id);
        }

        $document = $this->fieldSchemaDocument();

        if (hash('sha256', $document->canonicalJson()) !== $this->field_schema_sha256) {
            throw TemplateStateException::fieldSchemaDigestMismatch($this->public_id);
        }

        $revision = $this->documentRevision;
        $template = $this->template;

        return [
            'template_id' => (string) $template?->public_id,
            'template_name' => (string) $template?->name,
            'template_version_id' => $this->public_id,
            'version' => $this->version,
            'document_id' => $revision?->document?->public_id,
            'document_revision_id' => $this->document_revision_id,
            'document_revision_public_id' => $revision?->public_id,
            'document_revision_sha256' => $revision?->sha256,
            'document_page_count' => $revision?->page_count,
            'field_schema' => $document->toArray(),
            'field_schema_sha256' => $this->field_schema_sha256,
            'recipients' => $this->recipients,
            'consent_policy_version' => $this->consent_policy_version,
            'render_settings' => $this->renderSettings()->toArray(),
            'published_at' => (string) $this->published_at?->toIso8601String(),
        ];
    }
}
