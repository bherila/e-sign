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
 * The token must never reach the session store. Sessions are commonly a plaintext database table,
 * and both `url.intended` (set when a guest is sent to sign in) and the session's record of the
 * previous URL would otherwise hold the full link — a live credential anyone with a read-only copy
 * of that table could use. So the link itself is handled without a session at all: it moves the
 * token into a short-lived, encrypted, HTTP-only cookie scoped to `/invitations` and redirects to
 * `/invitations/accept`, which has no token in it. Only that page, and accepting on it, need a
 * signed-in person; a guest is sent to sign in and brought back to it.
 *
 * GET is harmless (AGENTS.md): following the link and showing the invitation change nothing, so a
 * mail scanner or a link preview cannot accept an invitation for anybody. Accepting is the POST.
 */
class InvitationRedemptionController extends Controller
{
    public const COOKIE = 'esign_invitation';

    /** Long enough to sign in, short enough that a forgotten tab does not keep a credential. */
    public const COOKIE_MINUTES = 30;

    public function __construct(private readonly WorkspaceMembers $members) {}

    /**
     * GET /invitations/{token}. Registered without the session middleware.
     */
    public function land(string $token): RedirectResponse
    {
        return redirect()
            ->route('invitations.accept')
            ->withCookie(cookie(
                self::COOKIE,
                $token,
                self::COOKIE_MINUTES,
                '/invitations',
                is_string(config('session.domain')) ? config('session.domain') : null,
                (bool) config('session.secure'),
                true,
                false,
                'lax',
            ))
            ->header('Cache-Control', 'no-store');
    }

    /**
     * GET /invitations/accept.
     */
    public function show(Request $request): Response
    {
        $user = $this->currentUser($request);
        $token = $this->token($request);
        $invitation = $token === null ? null : $this->members->openInvitationFor($token);
        $workspace = $invitation?->workspace;

        $alreadyMember = $invitation !== null && WorkspaceMembership::query()
            ->where('workspace_id', $invitation->workspace_id)
            ->where('user_id', $user->getKey())
            ->exists();

        return response()
            ->view('invitations.show', [
                'invitation' => $workspace === null ? null : $invitation,
                'workspace' => $workspace,
                'account' => $user,
                'alreadyMember' => $alreadyMember,
            ], $workspace === null ? 404 : 200)
            ->header('Cache-Control', 'no-store');
    }

    /**
     * POST /invitations/accept.
     */
    public function store(Request $request): RedirectResponse
    {
        $token = $this->token($request);

        if ($token === null) {
            return redirect()->route('invitations.accept');
        }

        try {
            $membership = $this->members->redeem($token, $this->currentUser($request));
        } catch (MembershipChangeRefused $refusal) {
            return redirect()
                ->route('invitations.accept')
                ->withErrors(['invitation' => $refusal->getMessage()]);
        }

        return redirect()
            ->route('dashboard')
            ->with('status', 'You joined '.$membership->workspace?->name.' as '.$membership->role->label().'.')
            ->withoutCookie(self::COOKIE, '/invitations', is_string(config('session.domain')) ? config('session.domain') : null);
    }

    private function token(Request $request): ?string
    {
        $value = $request->cookie(self::COOKIE);

        return is_string($value) && WorkspaceMembers::isWellFormedToken($value) ? $value : null;
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
