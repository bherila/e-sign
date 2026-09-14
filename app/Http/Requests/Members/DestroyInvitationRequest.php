<?php

declare(strict_types=1);

namespace App\Http\Requests\Members;

/**
 * DELETE /workspaces/{workspace}/invitations/{invitation}: revoke an open invitation.
 */
class DestroyInvitationRequest extends WorkspaceMembersRequest {}
