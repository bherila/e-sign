<?php

use App\Http\Controllers\Editor\FieldEditorController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Visual field editor (Stage 2, issue #22)
|--------------------------------------------------------------------------
|
| Its own file for the reason routes/health.php, routes/documents.php and
| routes/templates.php are: the file that defines a route also defines what
| protects it, in one place.
|
| One route, and it is a GET that renders a page. The editor writes through the
| template version PATCH endpoint in routes/templates.php, so there is exactly
| one place that validates and stores a field set — a second write path here
| would be a second definition of what a valid field document is.
|
| `web` brings the session the `auth` guard needs and the CSRF token the page
| hands to the React island. Authorization is not here:
| App\Http\Requests\Editor\ShowFieldEditorRequest resolves the workspace inside
| the caller's memberships and runs the workspace policy, so a cross-workspace
| identifier is a 404 rather than a 403.
|
| The parameter patterns mirror routes/templates.php exactly, including the two
| shapes `{version}` accepts, so the editor URL for a version is the version's
| own URL with `/editor` appended and nothing about addressing it changes.
|
*/

/** 1 to 999999999, no leading zero. Mirrors WorkspaceTemplateRequest::VERSION_NUMBER_PATTERN. */
$versionNumber = '[1-9][0-9]{0,8}';

/** Laravel's own ULID pattern, as used by `whereUlid()`. */
$ulid = '[0-7][0-9a-hjkmnp-tv-zA-HJKMNP-TV-Z]{25}';

Route::middleware(['web', 'auth'])
    ->prefix('workspaces/{workspace}')
    ->whereUlid(['workspace', 'template'])
    ->where(['version' => $versionNumber.'|'.$ulid])
    ->name('editor.')
    ->group(function (): void {
        Route::get('/templates/{template}/versions/{version}/editor', [FieldEditorController::class, 'show'])
            ->name('show');
    });
