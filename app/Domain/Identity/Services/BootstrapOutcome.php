<?php

declare(strict_types=1);

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Models\IdentityBinding;
use App\Domain\Identity\Models\Workspace;
use App\Domain\Identity\Models\WorkspaceMembership;
use App\Models\User;

/**
 * What `esign:bootstrap-owner` actually did, so a rerun can report "nothing to do" honestly
 * instead of claiming a change it did not make.
 */
final readonly class BootstrapOutcome
{
    /**
     * @param  list<string>  $changes  Human-readable descriptions, empty when this was a no-op.
     * @param  list<string>  $notes  Observations that are not changes (for example, an existing
     *                               workspace whose name differs from `--name`).
     */
    public function __construct(
        public Workspace $workspace,
        public User $user,
        public ?IdentityBinding $binding,
        public WorkspaceMembership $membership,
        public array $changes = [],
        public array $notes = [],
    ) {}

    public function changed(): bool
    {
        return $this->changes !== [];
    }
}
