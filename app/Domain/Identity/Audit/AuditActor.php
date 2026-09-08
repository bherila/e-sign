<?php

declare(strict_types=1);

namespace App\Domain\Identity\Audit;

use App\Models\User;

/**
 * Who performed an audited action.
 *
 * An actor is a type plus a stable identifier, never a bare email address, so the trail
 * stays readable after a person's contact details change.
 */
final readonly class AuditActor
{
    private function __construct(
        public string $type,
        public ?string $id,
        public ?string $label,
    ) {}

    /**
     * A console operator. There is no authenticated user during provisioning, so the label
     * records the command that ran rather than pretending someone was signed in.
     */
    public static function console(string $command): self
    {
        return new self('cli', null, $command);
    }

    public static function user(User $user): self
    {
        return new self('user', (string) $user->getKey(), $user->name);
    }

    public static function system(string $label): self
    {
        return new self('system', null, $label);
    }
}
