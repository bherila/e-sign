<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Webhooks\Console;

use App\Domain\Delivery\Webhooks\Models\OutboxEvent;
use App\Domain\Delivery\Webhooks\Models\WebhookEndpoint;
use App\Domain\Identity\Audit\AuditActor;
use App\Domain\Identity\Models\Workspace;
use Illuminate\Console\Command;

/**
 * Shared lookups for the webhook administration commands.
 *
 * Everything is addressed by its public identifier — a workspace by slug or
 * public id, an endpoint or an event by public id — never by the autoincrement
 * key, so a runbook, a log line, and a command line all name the same thing.
 */
abstract class WebhookCommand extends Command
{
    protected function actor(): AuditActor
    {
        return AuditActor::console($this->getName() ?? 'esign:webhook');
    }

    protected function findWorkspace(string $identifier): ?Workspace
    {
        return Workspace::query()
            ->where('slug', $identifier)
            ->orWhere('public_id', $identifier)
            ->first();
    }

    protected function findEndpoint(string $publicId): ?WebhookEndpoint
    {
        return WebhookEndpoint::query()->where('public_id', $publicId)->first();
    }

    protected function findEvent(string $publicId): ?OutboxEvent
    {
        return OutboxEvent::query()->where('public_id', $publicId)->first();
    }

    protected function refuse(string $problem, string $remedy): int
    {
        $this->components->error($problem);
        $this->line('  '.$remedy);

        return self::FAILURE;
    }
}
