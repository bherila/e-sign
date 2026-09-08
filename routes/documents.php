<?php

use App\Http\Controllers\Documents\DocumentController;
use App\Http\Controllers\Documents\DocumentRevisionDownloadController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Document preparation routes (Stage 2, issue #19)
|--------------------------------------------------------------------------
|
| Registered from bootstrap/app.php's `then:` closure rather than merged into
| routes/web.php, for the same reason routes/health.php is: this file owns its
| own middleware stack and says so in one place.
|
| `web` brings the session the `auth` guard needs and CSRF protection for the
| upload. Authorization is not here: each route's Form Request resolves the
| workspace inside the caller's memberships and runs the workspace policy, so
| a route cannot be added without one.
|
| Downloads stream through the application (docs/BLOB_STORAGE.md). There is
| deliberately no route that mints a URL for storage, and no public symlink
| points at the documents disk.
|
| Every parameter is a ULID. Constraining them keeps a malformed id a 404 at
| the router instead of a database query, and keeps the autoincrement ids off
| this surface entirely.
|
*/

Route::middleware(['web', 'auth'])
    ->prefix('workspaces/{workspace}')
    ->whereUlid(['workspace', 'document', 'revision'])
    ->name('documents.')
    ->group(function (): void {
        Route::post('/documents', [DocumentController::class, 'store'])
            ->name('store');

        Route::get('/documents/{document}', [DocumentController::class, 'show'])
            ->name('show');

        // Attachment. Two routes rather than one that guesses: a response can carry only
        // one Content-Disposition, and an iframe needs the inline one.
        Route::get('/documents/{document}/revisions/{revision}/download', [DocumentRevisionDownloadController::class, 'download'])
            ->name('revisions.download');

        // Inline, for the locally served PDF.js viewer.
        Route::get('/documents/{document}/revisions/{revision}/view', [DocumentRevisionDownloadController::class, 'view'])
            ->name('revisions.view');
    });
