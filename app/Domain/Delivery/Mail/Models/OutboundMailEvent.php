<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Mail\Models;

use App\Domain\Delivery\Mail\MailEventSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One attempt, or one piece of provider feedback, about one message.
 *
 * Append-only in practice: there is a `created_at` and no `updated_at`, and nothing in the
 * application updates a row here. An event is a claim someone made at a point in time, and
 * rewriting it would destroy the only record of what was claimed.
 *
 * `outbound_mail_id` is null on an orphan — feedback about a Message-ID this deployment has
 * no row for. See the migration for why those are kept rather than dropped.
 *
 * @property int $id
 * @property int|null $outbound_mail_id
 * @property MailEventSource $source
 * @property string $event
 * @property string|null $message_id
 * @property array<string, mixed>|null $payload
 * @property Carbon|null $occurred_at
 * @property Carbon|null $created_at
 */
class OutboundMailEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'outbound_mail_id',
        'source',
        'event',
        'message_id',
        'payload',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'source' => MailEventSource::class,
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<OutboundMail, $this> */
    public function mail(): BelongsTo
    {
        return $this->belongsTo(OutboundMail::class, 'outbound_mail_id');
    }

    public function isOrphan(): bool
    {
        return $this->outbound_mail_id === null;
    }
}
