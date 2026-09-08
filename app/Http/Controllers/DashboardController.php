<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Identity\Models\WorkspaceMembership;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Where a signed-in person lands.
 *
 * Read-only, and honest about the common case on a new installation: authenticating gets
 * somebody in, a membership row is what gets them a workspace, and until an owner grants
 * one there is genuinely nothing here. Saying so is better than an empty table that looks
 * like a bug.
 */
class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        $memberships = WorkspaceMembership::query()
            ->with('workspace')
            ->where('user_id', $user?->getKey())
            ->get()
            ->filter(fn (WorkspaceMembership $membership): bool => $membership->workspace !== null)
            ->sortBy(fn (WorkspaceMembership $membership): string => (string) $membership->workspace?->name)
            ->values();

        if ($memberships->isEmpty()) {
            return view('auth.no-workspace');
        }

        return view('dashboard', [
            'workspaces' => $memberships->map(fn (WorkspaceMembership $membership): array => [
                'publicId' => (string) $membership->workspace?->public_id,
                'name' => (string) $membership->workspace?->name,
                'slug' => (string) $membership->workspace?->slug,
                'role' => $membership->role->value,
                'roleLabel' => $membership->role->label(),
            ])->all(),
        ]);
    }
}
