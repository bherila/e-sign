<?php

declare(strict_types=1);

namespace App\Domain\Identity\Audit;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * An append-only application audit event.
 *
 * The Evidence module owns the envelope and artifact audit trail (docs/ARCHITECTURE.md);
 * this is the shared `esign_audit_events` store it will write into. Identity is its only
 * writer today, because provisioning is the first thing that happens on a new instance and
 * it happens before there is anything to sign.
 *
 * Append-only is enforced here rather than with a database trigger, which is not portable
 * across SQLite, MySQL, and MariaDB. Be precise about what that buys: the model events
 * below refuse an update or a delete through *this model*, and nothing more. They are not
 * reached by `AuditEvent::query()->update()`, `AuditEvent::query()->delete()`,
 * `saveQuietly()`, or `DB::table('esign_audit_events')->delete()`, all of which rewrite the
 * table freely. So this stops the ordinary mistake, not a determined caller.
 *
 * The only enforcement that holds against application code is the grant: a deployment that
 * needs the guarantee gives its database user INSERT and SELECT on this table and nothing
 * else. AuditEventTest pins both halves — the model refusals and the query-builder
 * bypass — so the boundary stays documented by a test rather than by this comment alone.
 *
 * @property string $actor_type
 * @property string|null $actor_id
 * @property string|null $actor_label
 * @property string $action
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property array<string, mixed>|null $payload
 */
class AuditEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'esign_audit_events';

    protected $fillable = [
        'actor_type',
        'actor_id',
        'actor_label',
        'action',
        'subject_type',
        'subject_id',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (self $event): never {
            throw new RuntimeException('esign_audit_events is append-only; audit events cannot be updated.');
        });

        static::deleting(static function (self $event): never {
            throw new RuntimeException('esign_audit_events is append-only; audit events cannot be deleted.');
        });
    }
}
