<?php

use App\Models\User;

// The route-family toggles below must track App\Domain\Identity\Enums\AuthMode's decision,
// but this file is `require`d by Illuminate\Foundation\Bootstrap\LoadConfiguration before the
// application container exists and before this very array has been merged into the config
// repository. That rules out calling AuthMode::current() here (it needs
// config('esign.auth_mode'), and "esign.php" sorts after "bherila-auth.php" so it has not
// loaded yet) and rules out OAuthClient::isConfigured() (it reads
// config('bherila-auth.oauth_client.*') — this same array — before it exists in the
// repository). So the auto/sso/local decision is duplicated here directly from env().
// AuthMode::fromSetting() remains the source of truth at request time, including its
// stricter validation: an unrecognised ESIGN_AUTH_MODE throws there, where here it only gates
// route registration and falls back to the 'auto' behaviour instead.
$esignAuthMode = strtolower(trim((string) env('ESIGN_AUTH_MODE', 'auto')));
$oauthClientConfigured = trim((string) env('OAUTH_CLIENT_ID', '')) !== ''
    && trim((string) env('OAUTH_PROVIDER_URL', '')) !== '';
$resolvedSsoMode = match ($esignAuthMode) {
    'local', 'standalone' => false,
    'sso' => true,
    default => $oauthClientConfigured,
};

// ESIGN_LOCAL_AUTH_ROUTES overrides the automatic choice: 'on' always registers the
// standalone-mode route families below, 'off' always drops them, and 'auto' (default)
// follows $resolvedSsoMode.
$localAuthRoutesSetting = strtolower(trim((string) env('ESIGN_LOCAL_AUTH_ROUTES', 'auto')));
$localAuthRoutesEnabled = match ($localAuthRoutesSetting) {
    'on' => true,
    'off' => false,
    default => ! $resolvedSsoMode,
};

return [
    // Package-owned API routes. Password reset, authenticated password change, email
    // two-factor, and passkeys are the standalone mode's supporting flows, and the
    // application owns the pages that call them. Nothing in SSO mode can use a local
    // password — there is no password login route, and the standalone controller refuses
    // any user that has an identity binding — so these four route families follow the
    // resolved auth mode automatically: enabled in standalone mode, disabled in SSO mode.
    // Override with ESIGN_LOCAL_AUTH_ROUTES=on|off; default is auto.
    'routes' => [
        'enabled' => true,
        'prefix' => 'api',
        'middleware' => ['web'],
        'passkeys' => $localAuthRoutesEnabled,
        'password_resets' => $localAuthRoutesEnabled,
        'change_password' => $localAuthRoutesEnabled,
        'two_factor' => $localAuthRoutesEnabled,
    ],

    'oauth_client' => [
        // Shared OAuth authorization-code + PKCE client mechanics. Applications still
        // own local user provisioning and authorization policy after identity resolution.
        'provider' => env('OAUTH_PROVIDER', 'bherila'),
        // No fallback hostname: an unconfigured install must resolve to standalone mode
        // (AuthMode::fromSetting()/OAuthClient::isConfigured()), not silently point at
        // whichever provider happened to be this package's original operator.
        'base_url' => env('OAUTH_PROVIDER_URL', ''),
        'client_id' => env('OAUTH_CLIENT_ID'),
        'client_secret' => env('OAUTH_CLIENT_SECRET'),
        // Must match the route this application registers (routes/web.php) and the exact
        // string registered at the provider.
        'redirect_uri' => env('OAUTH_REDIRECT_URI', rtrim((string) env('APP_URL'), '/').'/auth/callback'),
        'scope' => env('OAUTH_SCOPE', 'identity:read'),
        'authorize_path' => '/oauth/authorize',
        'token_path' => '/oauth/token',
        'identity_path' => '/api/oauth/user',
        // Relying-party initiated logout. Ending only the local session leaves the provider
        // still recognising the person, so the next sign-in returns them without a prompt.
        'end_session_path' => '/oauth/end-session',
    ],

    // Optional OAuth authorization-server helpers for applications exposing an
    // MCP or other protected API through Laravel Passport. Routes remain owned
    // by the application so this package never enables an authorization server
    // merely by being installed.
    'oauth_server' => [
        'enabled' => false,
        'issuer' => env('APP_URL', 'http://localhost'),
        'resource' => rtrim((string) env('APP_URL', 'http://localhost'), '/').'/api/v1',
        'authorization_endpoint' => rtrim((string) env('APP_URL', 'http://localhost'), '/').'/oauth/authorize',
        'token_endpoint' => rtrim((string) env('APP_URL', 'http://localhost'), '/').'/oauth/token',
        // This is intentionally null. Applications must expose and configure the
        // endpoint before it is advertised in authorization-server metadata.
        'registration_endpoint' => null,
        'scopes' => [],
        // Null means the protected-resource metadata helper uses the complete
        // application-owned scope catalog. Set a list when this resource exposes
        // only a subset of that catalog.
        'protected_resource_scopes' => null,
        'token_endpoint_auth_methods' => ['none'],
        // RFC 8707 has no discovery boolean. The resource parameter and the
        // protected-resource metadata document are the interoperable signals.
        'protected_resource_metadata_url' => null,
        'auth_code_resource_column' => 'resource_uri',
        'resource_column' => 'resource_uri',
        'refresh_token_resource_column' => 'resource_uri',
        'authorization_response_issuer' => [
            // RFC 9207 is opt-in because the authorization response middleware must
            // be installed on every authorization/consent route to make this true.
            'enabled' => false,
        ],
        // The application owns its scope policy. Keep the legacy scalar for
        // published-config compatibility, but do not assume a package scope.
        'resource_required_scope' => null,
        'resource_required_scopes' => [],
        'dynamic_clients' => [
            'enabled' => true,
            'required_columns' => ['dynamically_registered_at', 'scopes'],
            'registered_at_column' => 'dynamically_registered_at',
            'last_used_at_column' => null,
            'scopes_column' => 'scopes',
            // Retained for published-config compatibility; registered scopes are
            // always enforced for dynamic clients in the resource middleware.
            'enforce_registered_scopes' => true,
        ],
        'authorization_state' => [
            // Uses Laravel's default cache repository. It must persist across
            // requests and be shared by every authorization-server node.
            'cache_prefix' => 'oauth-resource:',
            'ttl_seconds' => null,
        ],
        'consent' => [
            'app_name' => env('APP_NAME', 'Application'),
            'heading' => 'Connect :client to :app?',
            'intro' => 'This application is requesting access to your :app account.',
            'identity' => true,
            'trust_warning' => 'Only continue if you recognize and trust this application. You can disconnect it later.',
            'dynamic_client_warning' => 'This application registered automatically. After approval, your browser returns to:',
            'policy_notice' => 'Your current permissions still apply to every request.',
            'approve_label' => 'Authorize',
            'deny_label' => 'Cancel',
        ],
        'introspection' => [
            // RFC 7662 is application-routed and opt-in. Each confidential
            // resource-server credential is pinned to one exact resource so an
            // introspector can never choose a broader audience at request time.
            // Store only a password_hash() result in each `secret_hash` value.
            'enabled' => false,
            'clients' => [],
        ],
    ],

    'oauth_resource_server' => [
        // Backchannel validation for a separately deployed authorization server.
        // Active responses are still checked locally against the exact issuer,
        // resource, audience, and temporal claims before they are trusted.
        'introspection_endpoint' => env('OAUTH_INTROSPECTION_ENDPOINT'),
        'client_id' => env('OAUTH_INTROSPECTION_CLIENT_ID'),
        'client_secret' => env('OAUTH_INTROSPECTION_CLIENT_SECRET'),
        'issuer' => env('OAUTH_RESOURCE_ISSUER'),
        'resource' => env('OAUTH_RESOURCE_URI'),
        'timeout_seconds' => (int) env('OAUTH_INTROSPECTION_TIMEOUT_SECONDS', 5),
    ],

    'migrations' => [
        'drop_tables_on_rollback' => false,
    ],

    'audit' => [
        // 'null' discards events; 'database' persists them to the audit table. Enabled here
        // because the login throttle below is backed by the same rows: with a null driver
        // nothing is recorded, so nothing is ever counted and the lockout silently does
        // nothing. Authentication events stay in this table; `esign_audit_events` is the
        // application trail (docs/ARCHITECTURE.md).
        'driver' => env('BHERILA_AUTH_AUDIT_DRIVER', 'database'),
        'table' => 'auth_audit_log',
        // Expose the package's read endpoints (own login history + admin list). Off by default.
        'routes_enabled' => env('BHERILA_AUTH_AUDIT_ROUTES', false),
        // null = retain forever (no pruning). Set a positive integer to enable `model:prune`.
        'retention_days' => env('BHERILA_AUTH_AUDIT_RETENTION_DAYS'),
        // Gate ability required for the cross-user admin endpoint; null disables that route.
        // IMPORTANT: the ability must verify that the user is active/approved AND is an admin.
        // The package enforces its own RequireActiveUser check on top of this gate, but the
        // gate should still verify account state independently so your Gate definition is
        // correct even when called from other locations. Example: check both ->is_admin and
        // ->approved_at, not just the role.
        'admin_ability' => env('BHERILA_AUTH_AUDIT_ADMIN_ABILITY'),
    ],

    'throttle' => [
        // Brute-force lockout backed by auth_audit_log rows, enforced by the standalone
        // password login controller. On by default: a self-hosted installation exposing a
        // password form on the public internet is the normal case, and a lockout that has
        // to be switched on is a lockout most deployments will not have.
        //
        // Applications behind a proxy must configure Laravel's trusted proxies, or every
        // request resolves to the proxy's address and the 'ip' half of the key groups every
        // visitor together.
        'enabled' => env('BHERILA_AUTH_THROTTLE_ENABLED', true),
        'max_attempts' => env('BHERILA_AUTH_THROTTLE_MAX_ATTEMPTS', 5),
        'decay_minutes' => env('BHERILA_AUTH_THROTTLE_DECAY_MINUTES', 15),
        // How failed attempts are grouped into a lockout key:
        //   'email'    — per account: count an email's failures across all source IPs
        //   'ip'       — per source: count an IP's failures across all emails
        //   'email_ip' — per account+source pair (most conservative; default)
        // Any other value falls back to 'email_ip'.
        'key' => env('BHERILA_AUTH_THROTTLE_KEY', 'email_ip'),
        'record_blocked' => env('BHERILA_AUTH_THROTTLE_RECORD_BLOCKED', true),
    ],

    'password_resets' => [
        'reset_url' => env('BHERILA_AUTH_PASSWORD_RESET_URL', env('APP_URL', '').'/reset-password/{token}?email={email}'),
        'request_url' => env('BHERILA_AUTH_PASSWORD_REQUEST_URL', '/forgot-password'),
        'redirect_after_reset' => env('BHERILA_AUTH_PASSWORD_RESET_REDIRECT', '/'),
        'mail_subject' => env('BHERILA_AUTH_PASSWORD_RESET_MAIL_SUBJECT', 'Reset your :app password'),
        'notice_subject' => env('BHERILA_AUTH_PASSWORD_NOTICE_MAIL_SUBJECT', 'Your :app password was changed'),
        'verify_email_on_reset' => false,
    ],

    'two_factor' => [
        'table' => 'auth_two_factor_attempts',
        'expires_minutes' => 15,
        // Fixed-code bypass for automated tests and local development. Off by default,
        // and even when on it applies only to accounts explicitly flagged `is_test`
        // AND only in the environments listed below (an empty list means any).
        // All three conditions must hold, so turning this off really does turn it off.
        'allow_test_code' => env('BHERILA_AUTH_ALLOW_TEST_2FA_CODE', false),
        'test_code_environments' => ['local', 'testing'],
        'test_code' => '999999',
        'mail_subject' => env('BHERILA_AUTH_TWO_FACTOR_MAIL_SUBJECT', 'Verify your login - :app'),
        'login_url' => env('BHERILA_AUTH_LOGIN_URL', '/login'),
        'session_user_key' => 'bherila_auth_2fa_user_id',
        'session_remember_key' => 'bherila_auth_2fa_remember',
    ],

    'passkeys' => [
        'table' => 'auth_passkeys',
        'rp_name' => env('WEBAUTHN_RP_NAME', env('APP_NAME', 'App')),
        // The registrable domain credentials bind to. Set this once per deployment
        // (for example `example.com`) so credentials stay valid across every subdomain
        // rather than being pinned to whichever host served the registration page.
        // Leave unset in local development to derive it from the request host.
        'rp_id' => env('WEBAUTHN_RP_ID'),
        'allowed_origins' => array_filter(array_map('trim', explode(',', env('WEBAUTHN_ALLOWED_ORIGINS', '')))),
        'timeout' => 60000,
        'resident_key' => env('WEBAUTHN_RESIDENT_KEY', 'preferred'),
        'user_verification' => env('WEBAUTHN_USER_VERIFICATION', 'preferred'),
    ],

    'users' => [
        'model' => config('auth.providers.users.model', User::class),
        'name_attribute' => 'name',
        'email_attribute' => 'email',
        'force_change_password_attribute' => null,
    ],
];
