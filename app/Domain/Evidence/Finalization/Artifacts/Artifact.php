<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Finalization\Artifacts;

use App\Domain\Evidence\Sealing\AssuranceLevel;
use App\Domain\Signing\Models\Envelope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * One published evidence object.
 *
 * The row exists only once its bytes have been written and read back through the storage
 * adapter with a matching digest, and it is inserted in the same transaction that completes
 * the envelope. That ordering is the whole of docs/ARCHITECTURE.md invariant 5: there is no
 * moment at which an envelope is completed and its artifact is not retrievable.
 *
 * Immutable after publication, enforced here rather than by a trigger for the portability
 * reason `document_revisions` and `recipient_attestations` both record. A correction is a new
 * generation with new keys.
 *
 * `disk` and `path` never appear in an HTTP response. Downloads stream through the
 * application after a policy has run; nothing presigns (docs/BLOB_STORAGE.md rule 1).
 *
 * @property int $id
 * @property string $public_id
 * @property int $envelope_id
 * @property ArtifactKind $kind
 * @property string $disk
 * @property string $path
 * @property string $sha256
 * @property int $bytes
 * @property int $generation
 * @property string $seal_key_id
 * @property string $seal_certificate_sha256
 * @property AssuranceLevel|null $assurance_level_reached
 * @property array<string, mixed>|null $validation_report
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable|null $created_at
 */
class Artifact extends Model
{
    /** Written once; there is no updated_at column. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'envelope_id',
        'kind',
        'disk',
        'path',
        'sha256',
        'bytes',
        'generation',
        'seal_key_id',
        'seal_certificate_sha256',
        'assurance_level_reached',
        'validation_report',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'kind' => ArtifactKind::class,
            'assurance_level_reached' => AssuranceLevel::class,
            'validation_report' => 'array',
            'bytes' => 'integer',
            'generation' => 'integer',
            'published_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $artifact): void {
            if (($artifact->public_id ?? '') === '') {
                $artifact->public_id = (string) Str::ulid();
            }
        });

        static::updating(static function (self $artifact): never {
            throw new RuntimeException(
                'artifacts is immutable once published; a corrected execution is a new generation '
                .'under new storage keys, never an edit of the evidence that already exists.',
            );
        });

        static::deleting(static function (self $artifact): never {
            throw new RuntimeException(
                'artifacts cannot be deleted. Executed evidence is retained until an operator has '
                .'configured a reviewed retention policy, and a legal hold overrides that policy '
                .'(docs/HANDOFF.md section 12).',
            );
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<Envelope, $this> */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }

    /** True once the publishing transaction committed. */
    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    /**
     * A filename safe to put in a Content-Disposition header.
     *
     * Built from the envelope title, never from anything an uploader or a signer supplied,
     * and reduced to an ASCII slug so nothing in it can carry a quote, a path separator, a
     * control character, or a right-to-left override. The digest prefix keeps two downloads
     * distinguishable on disk.
     */
    public function downloadFilename(string $title): string
    {
        $slug = Str::slug(Str::limit($title, 80, ''));

        if ($slug === '') {
            $slug = 'agreement';
        }

        return sprintf(
            '%s-%s-%s.%s',
            $slug,
            str_replace('_', '-', $this->kind->value),
            substr($this->sha256, 0, 12),
            $this->kind->extension(),
        );
    }
}
