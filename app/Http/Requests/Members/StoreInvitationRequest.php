<?php

declare(strict_types=1);

namespace App\Http\Requests\Members;

/**
 * POST /workspaces/{workspace}/invitations: create a single-use invitation link for a role.
 */
class StoreInvitationRequest extends AssignsWorkspaceRole {}
