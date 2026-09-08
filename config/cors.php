<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing
|--------------------------------------------------------------------------
|
| Published, and empty, deliberately.
|
| Laravel's `HandleCors` is in the global middleware stack whether or not this
| file exists. Without it the framework falls back to its own packaged default,
| which is `paths => ['api/*', 'sanctum/csrf-cookie']` with
| `allowed_origins => ['*']` — so `Access-Control-Allow-Origin: *` was being
| emitted for `/api/v1/*` and for the session-backed `/api/auth/*` and
| `/api/passkeys/*` routes the auth package registers
| (docs/security/review-2026-09.md finding X-8).
|
| The impact was bounded, because `supports_credentials` is false and a browser
| therefore withholds the `esign_session` cookie, and because `/api/v1` needs an
| `Authorization` header an attacker's page does not have. That is a posture the
| application inherited rather than chose, and the next person to turn
| `supports_credentials` on would have inherited `*` with it.
|
| Nothing in this product is a browser API. The native API is for service
| credentials, the guest signing surface is same-origin, and the admin UI is
| server-rendered. So no path is CORS-enabled, and a deployment that genuinely
| needs one names the exact origins here rather than discovering a wildcard.
|
*/

return [

    'paths' => [],

    'allowed_methods' => [],

    'allowed_origins' => [],

    'allowed_origins_patterns' => [],

    'allowed_headers' => [],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
