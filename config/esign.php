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
    | Document sealing (Stage 3)
    |--------------------------------------------------------------------------
    |
    | The service seal certificate and private key are versioned key material
    | kept outside the repository. The health probe only ever checks that the
    | files are readable and reports certificate expiry; it never reads or
    | exposes the private key contents.
    |
    */

    'seal' => [
        'certificate_path' => env('ESIGN_SEAL_CERTIFICATE_PATH'),
        'private_key_path' => env('ESIGN_SEAL_PRIVATE_KEY_PATH'),
    ],

    // RFC 3161 timestamp authority (Stage 3). A configured but unreachable TSA is an
    // error at signing time, never a silent downgrade; the health probe only checks
    // that the URL is well-formed, it never makes a network call.
    'tsa_url' => env('ESIGN_TSA_URL'),

    /*
    |--------------------------------------------------------------------------
    | Webhook outbox (Stage 2, issue #34)
    |--------------------------------------------------------------------------
    |
    | No outbox table exists yet, so the health probe reports a fixed "ok" with
    | a note until the Delivery module's webhook outbox lands.
    */

];
