<?php

use App\Http\Controllers\Templates\TemplateAliasController;
use App\Http\Controllers\Templates\TemplateController;
use App\Http\Controllers\Templates\TemplateVersionController;
use App\Http\Controllers\Templates\TemplateVersionSchemaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Template preparation routes (Stage 2, issue #21)
|--------------------------------------------------------------------------
|
| Registered from bootstrap/app.php's `then:` closure rather than merged into
| routes/web.php, for the same reason routes/health.php and routes/documents.php
| are: the file that defines a route also defines what protects it, in one place.
|
| `web` brings the session the `auth` guard needs and CSRF protection for the
| writes. Authorization is not here: every route's Form Request resolves the
| workspace inside the caller's memberships and runs the workspace policy
| (App\Http\Requests\Templates\WorkspaceTemplateRequest), so a route cannot be
| added without one. Reads take `view`, which every workspace role has; writes
| take `createTemplates`, which is sender and above — an auditor reads templates
| and versions and changes neither.
|
| `workspace` and `template` are ULIDs. Constraining them keeps a malformed id a
| 404 at the router instead of a database query, and keeps the autoincrement ids
| off this surface entirely.
|
| `version` is the one parameter with two shapes: the per-template number a human
| quotes (`/versions/2`) or the version's own ULID. Both resolve inside the
| already-resolved template, so neither can reach another tenant's version, and
| integration code that recorded "v2" does not have to look a ULID up first.
| Anything that is neither shape is a 404 at the router.
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
    ->name('templates.')
    ->group(function (): void {
        Route::get('/templates', [TemplateController::class, 'index'])
            ->name('index');

        Route::post('/templates', [TemplateController::class, 'store'])
            ->name('store');

        Route::get('/templates/{template}', [TemplateController::class, 'show'])
            ->name('show');

        // Name, description, and retirement. Nothing here can reach a version, which is
        // what keeps a rename from changing an envelope that is already out.
        Route::patch('/templates/{template}', [TemplateController::class, 'update'])
            ->name('update');

        Route::post('/templates/{template}/versions', [TemplateVersionController::class, 'store'])
            ->name('versions.store');

        Route::get('/templates/{template}/versions/{version}', [TemplateVersionController::class, 'show'])
            ->name('versions.show');

        // Drafts only. A published version answers 409 with the instruction to draft the
        // next one; there is deliberately no route that mutates a published version.
        Route::patch('/templates/{template}/versions/{version}', [TemplateVersionController::class, 'update'])
            ->name('versions.update');

        Route::post('/templates/{template}/versions/{version}/publish', [TemplateVersionController::class, 'publish'])
            ->name('versions.publish');

        // The canonical field-schema bytes, with the recorded digest in a header.
        Route::get('/templates/{template}/versions/{version}/schema.json', [TemplateVersionSchemaController::class, 'show'])
            ->name('versions.schema');

        // The alias travels in the body on both verbs, including DELETE: a provider template
        // id is somebody else's string and must not have to survive URL encoding.
        Route::post('/templates/{template}/aliases', [TemplateAliasController::class, 'store'])
            ->name('aliases.store');

        Route::delete('/templates/{template}/aliases', [TemplateAliasController::class, 'destroy'])
            ->name('aliases.destroy');
    });
