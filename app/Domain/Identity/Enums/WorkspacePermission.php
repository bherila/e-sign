<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

/**
 * Every authorization decision a workspace role can carry.
 *
 * The value of each case is the ability name used with Laravel's Gate, so
 * `$user->can(WorkspacePermission::ManageMembers->value, $workspace)` and
 * `Gate::authorize('manageMembers', $workspace)` are the same check.
 */
enum WorkspacePermission: string
{
    case View = 'view';
    case Update = 'update';
    case Delete = 'delete';
    case ManageMembers = 'manageMembers';
    case CreateTemplates = 'createTemplates';
    case CreateEnvelopes = 'createEnvelopes';
    case ReadAudit = 'readAudit';
    case RotateCredentials = 'rotateCredentials';
}
