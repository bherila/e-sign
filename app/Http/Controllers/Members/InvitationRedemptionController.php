<?php

declare(strict_types=1);

namespace App\Http\Controllers\Members;

use App\Domain\Identity\Models\WorkspaceMembership;
use App\Domain\Identity\Services\MembershipChangeRefused;
use App\Domain\Identity\Services\WorkspaceMembers;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;

/**
 * Following an invitation link, and accepting it (issue #110).
 *
 * Both routes sit behind `auth`, so a person who is not signed in is sent to sign in and brought
 * back here afterwards; the membership then binds to whichever account they signed in as.
 *
 * GET is harmless (AGENTS.md): showing the invitation reads it and changes nothing, so a mail
 * scanner or a link preview cannot accept an invitation for anybody. Accepting is the POST.
 */
class InvitationRedemptionController extends Controller
{
    public function __construct(private readonly WorkspaceMembers $members) {}

    public function show(Request $request, string $token): Response
    {
        $user = $this->currentUser($request);
        $invitation = $this->members->openInvitationFor($token);
        $workspace = $invitation?->workspace;

        $alreadyMember = $invitation !== null && WorkspaceMembership::query()
            ->where('workspace_id', $invitation->workspace_id)
            ->where('user_id', $user->getKey())
            ->exists();

        return response()
            ->view('invitations.show', [
                'token' => $token,
                'invitation' => $workspace === null ? null : $invitation,
                'workspace' => $workspace,
                'account' => $user,
                'alreadyMember' => $alreadyMember,
            ], $workspace === null ? 404 : 200)
            // The token is in the URL; nothing about this page may be kept by a cache.
            ->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        try {
            $membership = $this->members->redeem($token, $this->currentUser($request));
        } catch (MembershipChangeRefused $refusal) {
            return redirect()
                ->route('invitations.show', ['token' => $token])
                ->withErrors(['invitation' => $refusal->getMessage()]);
        }

        return redirect()
            ->route('dashboard')
            ->with('status', 'You joined '.$membership->workspace?->name.' as '.$membership->role->label().'.');
    }

    private function currentUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new RuntimeException('An invitation route ran without an authenticated user.');
        }

        return $user;
    }
}
