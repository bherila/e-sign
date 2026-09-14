<?php

declare(strict_types=1);

use App\Http\Controllers\Members\InvitationRedemptionController;
use App\Http\Controllers\Members\MembersController;
use App\Http\Controllers\Members\WorkspaceInvitationController;
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
| Invitation links carry a 64-hex-character token. Following one (GET) shows
| it and changes nothing; accepting it is a POST, so a mail scanner or a link
| preview can never join anybody to a workspace.
|
*/

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

    Route::get('/invitations/{token}', [InvitationRedemptionController::class, 'show'])
        ->where('token', '[0-9a-f]{64}')
        ->name('invitations.show');
    Route::post('/invitations/{token}', [InvitationRedemptionController::class, 'store'])
        ->where('token', '[0-9a-f]{64}')
        ->middleware('throttle:invitation-redemptions')
        ->name('invitations.redeem');
});
