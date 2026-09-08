<?php

declare(strict_types=1);

namespace App\Domain\Identity\Audit;

use Illuminate\Database\Eloquent\Model;

/**
 * The only supported way to write an audit event.
 *
 * Going through a recorder keeps action names and actor shapes consistent and gives the
 * Evidence module one place to hook when it takes over the wider trail.
 */
class AuditRecorder
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(AuditActor $actor, string $action, ?Model $subject = null, array $payload = []): AuditEvent
    {
        return AuditEvent::create([
            'actor_type' => $actor->type,
            'actor_id' => $actor->id,
            'actor_label' => $actor->label,
            'action' => $action,
            'subject_type' => $subject === null ? null : $subject::class,
            'subject_id' => $subject === null ? null : (string) $subject->getKey(),
            'payload' => $payload === [] ? null : $payload,
        ]);
    }
}
