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
    | Document intake (Stage 2, issue #19)
    |--------------------------------------------------------------------------
    |
    | Limits applied to an uploaded PDF before anything else touches it. They
    | are enforced twice on purpose: the Form Request rejects an oversized body
    | before it is parsed, and the preflight parser re-checks the same ceiling
    | against the bytes it actually received.
    |
    | The defaults come from the measured cost table in docs/stage0/pdf-import.md
    | (roughly 0.03 ms/page preflight and 50 KB/page of peak memory on
    | text-dense pages). They are configuration, not an invariant, and are
    | expected to move once there is a corpus of real uploads.
    |
    | Nesting depth and the decompression ceiling are not separately
    | configurable: the object-graph walk fixes its recursion depth at 32, and
    | decompression is bounded by `max_decoded_stream_bytes` per stream. See
    | App\Domain\Preparation\Preflight\PreflightLimits.
    |
    */

    'documents' => [
        // A disk *name*, resolved through config/filesystems.php. Code never
        // branches on the driver behind it, and it is never presigned.
        'disk' => env('ESIGN_DOCUMENTS_DISK', 'documents'),

        'max_bytes' => (int) env('ESIGN_DOCUMENTS_MAX_BYTES', 33_554_432),
        'max_pages' => (int) env('ESIGN_DOCUMENTS_MAX_PAGES', 500),
        'max_objects' => (int) env('ESIGN_DOCUMENTS_MAX_OBJECTS', 100_000),
        'max_decoded_stream_bytes' => (int) env('ESIGN_DOCUMENTS_MAX_DECODED_STREAM_BYTES', 33_554_432),

        // The MIME types the upload Form Request accepts, checked against the
        // file's sniffed type rather than its name or its declared header. This
        // is a first gate only; preflight then parses the document for real.
        'allowed_mimetypes' => ['application/pdf'],

        'normalization' => [
            // Rebuild every accepted document through the importer before it
            // becomes the review revision.
            //
            // Off by default, and that default is a fidelity decision rather
            // than a performance one: tc-lib-pdf 8.73 drops annotations and
            // /UserUnit on import and emits fresh document identifiers each run
            // (docs/stage0/pdf-import.md, findings 2, 3 and 6). Rebuilding a
            // document that needs no rebuilding would therefore lose content
            // and produce a review revision that cannot be reproduced from the
            // original. With it off, the review revision is the original bytes
            // and carries the original digest.
            //
            // The switch exists because normalization steps that genuinely have
            // to run before review — AcroForm flattening is the expected first
            // one — need this path to be built and tested, not invented later.
            // Whatever it does is recorded on the revision and disclosed to the
            // sender; see docs/preparation/documents.md.
            'rebuild_pages' => filter_var(
                env('ESIGN_DOCUMENTS_NORMALIZE_REBUILD_PAGES', false),
                FILTER_VALIDATE_BOOLEAN
            ),
        ],
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
    | Templates (Stage 2, issue #21)
    |--------------------------------------------------------------------------
    |
    | The consent policy version a new template version records when the
    | caller does not name one. A template version snapshots it, so the
    | attestation can say which consent text the signer was shown even after
    | this setting has moved on (docs/HANDOFF.md sections 6 and 8).
    |
    | It is deployment configuration rather than a table: the consent policy is
    | drafted and approved outside this application, and its history outlives
    | anything this schema owns. Changing it affects versions published after
    | the change and nothing else — the point of the snapshot.
    |
    */

    'templates' => [
        'default_consent_policy_version' => (string) env(
            'ESIGN_CONSENT_POLICY_VERSION',
            '2026-09-01'
        ),
    ],

];
