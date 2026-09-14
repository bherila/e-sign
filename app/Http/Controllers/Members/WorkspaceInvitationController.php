<?php

declare(strict_types=1);

namespace App\Http\Controllers\Members;

use App\Domain\Identity\Services\MembershipChangeRefused;
use App\Domain\Identity\Services\WorkspaceMembers;
use App\Http\Controllers\Controller;
use App\Http\Requests\Members\DestroyInvitationRequest;
use App\Http\Requests\Members\StoreInvitationRequest;
use Illuminate\Http\JsonResponse;
use RuntimeException;

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
        // Checked before anything is created, so a misconfigured deployment leaves no open
        // invitation behind a link nobody was given.
        $root = self::configuredRoot();

        try {
            [, $token] = $this->members->invite($request->workspace(), $request->currentUser(), $request->role());
        } catch (MembershipChangeRefused $refusal) {
            return $this->refused($refusal);
        }

        return response()->json([
            'url' => $root.route('invitations.show', ['token' => $token], false),
            'state' => $this->membersPayload($this->members, $request->workspace(), $request->currentUser()),
        ], 201)->header('Cache-Control', 'no-store');
    }

    /**
     * The deployment's own origin, as `InvitationIssuer::urlFor()` uses for signing links.
     *
     * Never the request's: without a trusted-hosts list, a request can name any `Host`, and a link
     * built from it would hand a live token to whatever origin that request chose.
     */
    private static function configuredRoot(): string
    {
        $root = rtrim((string) config('app.url', ''), '/');

        if ($root === '') {
            throw new RuntimeException('APP_URL is not configured, so an invitation link cannot be built.');
        }

        return $root;
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
