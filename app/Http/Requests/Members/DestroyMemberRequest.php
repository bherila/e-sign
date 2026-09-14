<?php

declare(strict_types=1);

namespace App\Http\Requests\Members;

/**
 * DELETE /workspaces/{workspace}/members/{member}: remove one membership, and nothing else.
 */
class DestroyMemberRequest extends WorkspaceMembersRequest {}
