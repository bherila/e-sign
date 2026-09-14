<?php

declare(strict_types=1);

namespace App\Http\Controllers\Members;

use App\Domain\Identity\Services\MembershipChangeRefused;
use App\Domain\Identity\Services\WorkspaceMembers;
use App\Http\Controllers\Controller;
use App\Http\Requests\Members\DestroyInvitationRequest;
use App\Http\Requests\Members\StoreInvitationRequest;
use Illuminate\Http\JsonResponse;

/**
 * Creating and revoking invitation links (issue #110).
 *
 * The link is in the creation response and nowhere else: only a digest of its token is stored,
 * so the owner or administrator who creates it has to copy it now.
 */
class WorkspaceInvitationController extends Controller
{
    use RespondsWithMembers;

    public function __construct(private readonly WorkspaceMembers $members) {}

    public function store(StoreInvitationRequest $request): JsonResponse
    {
        try {
            [, $token] = $this->members->invite($request->workspace(), $request->currentUser(), $request->role());
        } catch (MembershipChangeRefused $refusal) {
            return $this->refused($refusal);
        }

        return response()->json([
            'url' => route('invitations.show', ['token' => $token]),
            'state' => $this->membersPayload($this->members, $request->workspace(), $request->currentUser()),
        ], 201)->header('Cache-Control', 'no-store');
    }

    public function destroy(DestroyInvitationRequest $request): JsonResponse
    {
        try {
            $this->members->revokeInvitation($request->workspace(), $request->currentUser(), $request->invitation());
        } catch (MembershipChangeRefused $refusal) {
            return $this->refused($refusal);
        }

        return response()->json($this->membersPayload($this->members, $request->workspace(), $request->currentUser()));
    }
}
