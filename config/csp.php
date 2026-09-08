<?php

use Spatie\Csp\Directive;
use Spatie\Csp\Keyword;

/*
|--------------------------------------------------------------------------
| Content Security Policy — the application's ordinary pages
|--------------------------------------------------------------------------
|
| Emitted by Spatie's `AddCspHeaders`, which `AppServiceProvider` pushes onto
| the global stack. The guest signing surface does *not* use this policy:
| `App\Http\Middleware\SigningSecurityHeaders` sets a narrower one as route
| middleware, and `AddCspHeaders` returns early when a response already
| carries a `Content-Security-Policy`.
|
| ## Why this file is written against `presets`/`directives`
|
| It used to declare `'policy' => App\Csp\CloudflareCspPolicy::class`, which is
| `spatie/laravel-csp` v2's API. v3 — the version this repository pins and
| installs — reads `presets`, `directives`, `report_uri`, and `report_to`, and
| never looks at `policy`. So the configured policy was inert, and the header
| every non-signing page actually carried was the package's own `Basic` preset:
| no `frame-ancestors`, and a nonce in `style-src` that nothing in
| `resources/` emits. `CloudflareCspPolicy` extended `Spatie\Csp\Policies\Policy`,
| a class v3 does not ship, so it could not even be autoloaded — it survived
| only because a class-string in configuration is not autoloaded until something
| instantiates it. See docs/security/review-2026-09.md finding X-1; the class is
| gone and `SecurityHeadersTest` now asserts the emitted header rather than the
| configured intent.
|
| ## The policy
|
| `default-src 'self'` and no third-party origin. The analytics host the old
| policy named is not here: nothing in `resources/` loads it, AGENTS.md forbids
| it on the signing surface, and a policy should not admit an origin the
| application does not use.
|
| `style-src` carries `'unsafe-inline'` for the same reason the signing policy
| does — React writes element `style` attributes for the editor and field
| overlays, and CSP counts an attribute as inline style. `script-src` does not,
| and neither directive admits `'unsafe-eval'`.
|
| `frame-ancestors 'none'` is the one that was missing. Every authenticated page
| here publishes templates, deletes aliases, or saves field geometry, and all of
| that was framable.
|
*/

return [

    'presets' => [],

    'directives' => [
        [Directive::DEFAULT, [Keyword::SELF]],
        [Directive::SCRIPT, [Keyword::SELF]],
        [Directive::STYLE, [Keyword::SELF, Keyword::UNSAFE_INLINE]],
        [Directive::IMG, [Keyword::SELF, 'data:', 'blob:']],
        [Directive::FONT, [Keyword::SELF, 'data:']],
        [Directive::CONNECT, [Keyword::SELF]],
        [Directive::WORKER, [Keyword::SELF, 'blob:']],
        [Directive::OBJECT, [Keyword::NONE]],
        [Directive::BASE, [Keyword::NONE]],
        [Directive::FRAME_ANCESTORS, [Keyword::NONE]],
        [Directive::FORM_ACTION, [Keyword::SELF]],
    ],

    'report_only_presets' => [],

    'report_only_directives' => [],

    'report_uri' => env('CSP_REPORT_URI', ''),

    'report_only_uri' => env('CSP_REPORT_ONLY_URI', ''),

    'report_to' => env('CSP_REPORT_TO', ''),

    'report_only_to' => env('CSP_REPORT_ONLY_TO', ''),

    'reporting_endpoints' => [],

    'enabled' => env('CSP_ENABLED', true),

    'enabled_while_hot_reloading' => env('CSP_ENABLED_WHILE_HOT_RELOADING', false),

    // Off. No Blade template in this application emits `@cspNonce`, so a nonce
    // in `script-src`/`style-src` adds no protection and — because a nonce in
    // `style-src` suppresses the `'unsafe-inline'` beside it — actively breaks
    // the inline `style` attributes React writes for the field overlays.
    'nonce_enabled' => env('CSP_NONCE_ENABLED', false),
];
