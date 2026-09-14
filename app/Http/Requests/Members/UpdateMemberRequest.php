<?php

declare(strict_types=1);

namespace App\Http\Requests\Members;

/**
 * PATCH /workspaces/{workspace}/members/{member}: change one member's role.
 */
class UpdateMemberRequest extends AssignsWorkspaceRole {}
