<?php

declare(strict_types=1);

namespace App\Domain\Signing\Models;

use App\Domain\Preparation\Documents\Models\DocumentRevision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

/**
 * One member of an envelope's document set (issue #163). Written once, with the envelope, and
 * never changed: the set is part of what every acceptance binds (docs/preparation/document-sets.md).
 *
 * @property int $id
 * @property string $public_id
 * @property int $envelope_id
 * @property int $position
 * @property int $document_revision_id
 * @property string $title
 * @property string $sha256
 * @property int $page_count
 * @property int $first_page
 */
class EnvelopeDocument extends Model
{
    protected $fillable = [
        'envelope_id',
        'position',
        'document_revision_id',
        'title',
        'sha256',
        'page_count',
        'first_page',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $document): void {
            if (($document->public_id ?? '') === '') {
                $document->public_id = (string) Str::ulid();
            }
        });

        // Part of what every acceptance on the envelope is bound to.
        static::updating(static function (): never {
            throw new LogicException('An envelope document is immutable once written.');
        });
    }

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'page_count' => 'integer',
            'first_page' => 'integer',
        ];
    }

    /** @return BelongsTo<Envelope, $this> */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }

    /** @return BelongsTo<DocumentRevision, $this> */
    public function documentRevision(): BelongsTo
    {
        return $this->belongsTo(DocumentRevision::class);
    }
}
