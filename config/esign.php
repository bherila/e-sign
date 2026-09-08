<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Health and readiness (Stage 1, issue #16)
    |--------------------------------------------------------------------------
    |
    | The detailed /health/ready body is only returned to callers who present
    | this bearer token or connect from an allowlisted CIDR. Everyone else
    | gets the HTTP status code and a bare {"status": ...} body, which is
    | enough for a load balancer without leaking operational detail.
    |
    */

    'health_token' => env('ESIGN_HEALTH_TOKEN'),

    'health_allow_cidrs' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ESIGN_HEALTH_ALLOW_CIDRS', '127.0.0.1/32,::1/128'))
    ))),

    'health' => [
        // Queue lag thresholds, in seconds, based on the oldest pending job's
        // available_at timestamp.
        'queue_warn_seconds' => (int) env('ESIGN_HEALTH_QUEUE_WARN_SECONDS', 120),
        'queue_fail_seconds' => (int) env('ESIGN_HEALTH_QUEUE_FAIL_SECONDS', 600),

        // Scheduler heartbeat thresholds, in seconds, based on a cache key a
        // scheduled task refreshes every minute (see routes/console.php).
        'scheduler_warn_seconds' => (int) env('ESIGN_HEALTH_SCHEDULER_WARN_SECONDS', 180),
        'scheduler_fail_seconds' => (int) env('ESIGN_HEALTH_SCHEDULER_FAIL_SECONDS', 600),

        // Signing certificate expiry threshold, in days, for the signing-material probe.
        'cert_warn_days' => (int) env('ESIGN_HEALTH_CERT_WARN_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Service seal material
    |--------------------------------------------------------------------------
    |
    | The organizational seal applied to an executed PDF. It is the service's
    | own certificate, never a per-signer certificate: humans provide
    | electronic signatures/assent, and the service then seals the result.
    |
    | The paths point at key material kept outside the repository, image
    | layers, document storage, and logs. Absent, unreadable, expired, or
    | mismatched material is an error at seal time, never a silent skip.
    |
    */

    'seal' => [
        // Versioned identifier for the material below, recorded on every
        // artifact so an old document stays verifiable after a rotation.
        'key_id' => env('ESIGN_SEAL_KEY_ID', ''),

        // PEM X.509 certificate of the service seal.
        'certificate_path' => env('ESIGN_SEAL_CERTIFICATE_PATH', ''),

        // PEM private key matching the certificate above.
        'private_key_path' => env('ESIGN_SEAL_PRIVATE_KEY_PATH', ''),

        // Passphrase for an encrypted private key; empty for an unencrypted one.
        'private_key_passphrase' => env('ESIGN_SEAL_PRIVATE_KEY_PASSPHRASE', ''),

        // Optional PEM bundle holding the chain above the seal certificate,
        // leaf first. Embedded in the CMS so a relying party can build a path.
        'chain_path' => env('ESIGN_SEAL_CHAIN_PATH', ''),

        // CMS digest algorithm. sha256, sha384, and sha512 are accepted; SHA-1
        // is not offered.
        'digest_algorithm' => env('ESIGN_SEAL_DIGEST_ALGORITHM', 'sha256'),
    ],

    /*
    |--------------------------------------------------------------------------
    | RFC 3161 timestamp authority (PAdES B-T)
    |--------------------------------------------------------------------------
    |
    | An unset URL means the deployment can only produce B-B. Requesting B-T
    | without a configured, reachable, and valid TSA is an error: there is no
    | downgrade path from B-T to B-B.
    |
    | The destination is validated before the request leaves the process:
    | HTTPS by default, no credentials in the URL, no redirects followed, and
    | no host that resolves to a private, loopback, link-local, or otherwise
    | reserved address.
    |
    */

    'tsa' => [
        // Example: https://freetsa.org/tsr
        'url' => env('ESIGN_TSA_URL', ''),

        // Transport timeout in seconds for the timestamp request.
        'timeout' => (int) env('ESIGN_TSA_TIMEOUT', 15),

        // Some widely used public TSAs (DigiCert among them) publish an
        // RFC 3161 endpoint over plaintext HTTP only. The timestamp token is
        // signed and nonce-matched, so integrity does not rest on TLS, but the
        // document digest travels in the clear; opting in is deliberate.
        'allow_plaintext_http' => filter_var(
            env('ESIGN_TSA_ALLOW_PLAINTEXT_HTTP', false),
            FILTER_VALIDATE_BOOLEAN
        ),

        // A TSA that still names its certificate with the RFC 2634
        // signing-certificate (v1) attribute is SHA-1 by definition and is
        // refused without this. It relaxes the token check only, never the
        // document signature.
        'allow_sha1_token' => filter_var(
            env('ESIGN_TSA_ALLOW_SHA1_TOKEN', false),
            FILTER_VALIDATE_BOOLEAN
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication mode (Stage 1, issues #12 and #13)
    |--------------------------------------------------------------------------
    |
    | Which sign-in surface this deployment exposes. See
    | App\Domain\Identity\Enums\AuthMode and docs/operations/bootstrap.md.
    |
    |   auto   Single sign-on when an OAuth client has been issued for this
    |          application, standalone password login when it has not. The
    |          default, because an unconfigured install has to be able to
    |          start.
    |   sso    Always single sign-on. A missing setting is then an outage
    |          (503) rather than a silent fall back to a password form, which
    |          is what a deployment that means to use a provider wants.
    |   local  Always standalone password login, even where an OAuth client
    |          is configured.
    |
    | Routes are registered per mode, so run `php artisan route:clear` after
    | changing this on a deployment that caches routes.
    |
    */

    'auth_mode' => env('ESIGN_AUTH_MODE', 'auto'),

    /*
    |--------------------------------------------------------------------------
    | Whether the operator actually set the provider URL
    |--------------------------------------------------------------------------
    |
    | `bherila-auth.oauth_client.base_url` carries a package default
    | (`https://bherila.net`), so it is never empty and cannot answer "did this
    | operator configure a provider?". Reading the raw variable through config
    | rather than calling env() at the call site keeps the answer correct under
    | `config:cache`, where env() returns null.
    |
    | Consumed by esign:bootstrap-owner, which must refuse to bind an SSO owner
    | to a provider nobody chose.
    |
    | `oauth_provider` is here for the same reason: the package defaults that
    | key to `bherila`, so it too can never read as unset.
    |
    */

    'oauth_provider_url' => env('OAUTH_PROVIDER_URL', ''),

    'oauth_provider' => env('OAUTH_PROVIDER', ''),

];
