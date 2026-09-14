<?php

declare(strict_types=1);

namespace App\Http\Requests\Members;

/**
 * GET /workspaces/{workspace}/members.
 *
 * Owner and administrator only. Other roles see their own role on the dashboard; the member list
 * is part of administering the workspace, not of working in it.
 */
class ShowMembersRequest extends WorkspaceMembersRequest {}
