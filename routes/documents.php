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
        // Throttled, and it is the only route here that is. Preflight parses the uploaded
        // PDF synchronously inside the request, and the parser's decompression ceiling is
        // per stream rather than aggregate — so a small file with enough streams can spend
        // the whole memory limit before any application ceiling is consulted
        // (docs/security/review-2026-09.md findings U-1 and U-2, both open). Bounding how
        // often one member can trigger that is not the fix; it is what keeps the residual to
        // an authenticated workspace member and a countable number of attempts until the
        // parser grows an aggregate budget.
        Route::post('/documents', [DocumentController::class, 'store'])
            ->middleware('throttle:document-uploads')
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
