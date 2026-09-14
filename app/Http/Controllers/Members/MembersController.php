<?php

declare(strict_types=1);

namespace App\Http\Controllers\Members;

use App\Domain\Identity\Services\MembershipChangeRefused;
use App\Domain\Identity\Services\WorkspaceMembers;
use App\Http\Controllers\Controller;
use App\Http\Requests\Members\DestroyMemberRequest;
use App\Http\Requests\Members\ShowMembersRequest;
use App\Http\Requests\Members\UpdateMemberRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

/**
 * A workspace's members: who has which role, changing a role, and removing access (issue #110).
 *
 * Every rule is `WorkspaceMembers`'. This controller resolves the request, calls it, and answers
 * with the page's current state or the service's refusal.
 */
class MembersController extends Controller
{
    use RespondsWithMembers;

    public function __construct(private readonly WorkspaceMembers $members) {}

    public function index(ShowMembersRequest $request): View|JsonResponse
    {
        $payload = $this->membersPayload($this->members, $request->workspace(), $request->currentUser());

        if ($request->expectsJson()) {
            return response()->json($payload);
        }

        return view('members.index', ['workspace' => $request->workspace(), 'payload' => $payload]);
    }

    public function update(UpdateMemberRequest $request): JsonResponse
    {
        try {
            $this->members->changeRole($request->workspace(), $request->currentUser(), $request->membership(), $request->role());
        } catch (MembershipChangeRefused $refusal) {
            return $this->refused($refusal);
        }

        return response()->json($this->membersPayload($this->members, $request->workspace(), $request->currentUser()));
    }

    public function destroy(DestroyMemberRequest $request): JsonResponse
    {
        try {
            $this->members->remove($request->workspace(), $request->currentUser(), $request->membership());
        } catch (MembershipChangeRefused $refusal) {
            return $this->refused($refusal);
        }

        return response()->json($this->membersPayload($this->members, $request->workspace(), $request->currentUser()));
    }
}
