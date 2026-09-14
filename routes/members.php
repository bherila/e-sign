<?php

declare(strict_types=1);

use App\Http\Controllers\Members\InvitationRedemptionController;
use App\Http\Controllers\Members\MembersController;
use App\Http\Controllers\Members\WorkspaceInvitationController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Workspace members and invitations (issue #110)
|--------------------------------------------------------------------------
|
| The members page is owner and administrator only; the Form Requests resolve
| the workspace through membership, so a workspace the caller is not in is a
| 404. Every rule about who may grant what lives in WorkspaceMembers.
|
| Invitation links carry a 64-hex-character token, and that token must never
| reach the session store. The link route therefore runs WITHOUT the `web`
| group: no session starts, so neither `url.intended` nor the previous-URL
| record can hold it. It moves the token into an encrypted cookie and
| redirects to /invitations/accept. Showing that page changes nothing;
| accepting is a POST, so a mail scanner or a link preview can never join
| anybody to a workspace.
|
*/

Route::get('/invitations/{token}', [InvitationRedemptionController::class, 'land'])
    ->middleware([EncryptCookies::class, AddQueuedCookiesToResponse::class])
    ->where('token', '[0-9a-f]{64}')
    ->name('invitations.show');

Route::middleware(['web', 'auth'])->group(function (): void {
    Route::prefix('workspaces/{workspace}')
        ->whereUlid(['workspace', 'member', 'invitation'])
        ->name('members.')
        ->group(function (): void {
            Route::get('/members', [MembersController::class, 'index'])->name('index');
            Route::patch('/members/{member}', [MembersController::class, 'update'])->name('update');
            Route::delete('/members/{member}', [MembersController::class, 'destroy'])->name('destroy');

            Route::post('/invitations', [WorkspaceInvitationController::class, 'store'])
                ->middleware('throttle:member-invitations')
                ->name('invitations.store');
            Route::delete('/invitations/{invitation}', [WorkspaceInvitationController::class, 'destroy'])
                ->name('invitations.destroy');
        });

    Route::get('/invitations/accept', [InvitationRedemptionController::class, 'show'])
        ->name('invitations.accept');
    Route::post('/invitations/accept', [InvitationRedemptionController::class, 'store'])
        ->middleware('throttle:invitation-redemptions')
        ->name('invitations.redeem');
});
