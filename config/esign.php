<?php

use App\Domain\Delivery\Outbound\DestinationAllowlist;

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
    | Delivery: outbound destinations and the webhook outbox (Stage 4, issue #34)
    |--------------------------------------------------------------------------
    |
    | Every outbound request to a stored URL — a webhook endpoint, the RFC 3161
    | timestamp authority — passes App\Domain\Delivery\Outbound\DestinationPolicy
    | first: HTTPS only, no credentials in the URL, no redirects followed, and no
    | host that resolves to a loopback, private, link-local, carrier-grade-NAT, or
    | otherwise reserved address.
    |
    | The allowlist below is the ONLY way past the private-address and plaintext
    | refusals, and it is deployment configuration: a workspace administrator who
    | can create a webhook endpoint cannot use it to aim delivery at the instance's
    | own metadata service. Compact form, comma-separated:
    |
    |   ESIGN_DELIVERY_ALLOWLIST="consumer.internal.example|private|plaintext,10.8.0.0/24|private"
    |
    */

    'delivery' => [

        'destination_allowlist' => DestinationAllowlist::parseEnvironment(
            env('ESIGN_DELIVERY_ALLOWLIST')
        ),

        'webhooks' => [
            // Response deadline for one delivery attempt, in seconds. The
            // compatibility profile documents 5 s; ours is configurable.
            'timeout' => (int) env('ESIGN_WEBHOOK_TIMEOUT', 5),
            'connect_timeout' => (int) env('ESIGN_WEBHOOK_CONNECT_TIMEOUT', 5),

            // Queue the delivery and dispatch jobs run on.
            'queue' => env('ESIGN_WEBHOOK_QUEUE', 'default'),

            // Backoff between attempts, in seconds, applied after attempt 1, 2, …
            // in order. An attempt past the end of the list is not made: the
            // delivery is exhausted. Seven attempts over ~40 h by default.
            'retry_delays' => array_values(array_filter(array_map(
                'intval',
                explode(',', (string) env('ESIGN_WEBHOOK_RETRY_DELAYS', '60,300,1800,7200,43200,86400'))
            ), fn (int $seconds): bool => $seconds > 0)),

            // Fraction of each delay applied as +/- jitter, so a receiver that
            // dropped every endpoint at once does not get every retry at once.
            'retry_jitter' => (float) env('ESIGN_WEBHOOK_RETRY_JITTER', 0.1),

            // Consecutive exhausted or terminally failed events before the
            // endpoint is auto-disabled with a visible reason. The compatibility
            // profile documents 50; ours is lower and configurable.
            'auto_disable_after' => (int) env('ESIGN_WEBHOOK_AUTO_DISABLE_AFTER', 10),

            // How long a rotated-out secret keeps verifying, in hours.
            'secret_rotation_grace_hours' => (int) env('ESIGN_WEBHOOK_ROTATION_GRACE_HOURS', 168),

            // Bytes of a receiver's response body retained for diagnosis, after
            // redaction. Never the whole body.
            'response_excerpt_bytes' => (int) env('ESIGN_WEBHOOK_RESPONSE_EXCERPT_BYTES', 1024),

            'user_agent' => env('ESIGN_WEBHOOK_USER_AGENT', 'BWH-eSign-Webhooks/1'),

            // Age of the oldest overdue delivery, in seconds, at which the
            // webhook-backlog health probe warns and then fails.
            'backlog_warn_seconds' => (int) env('ESIGN_WEBHOOK_BACKLOG_WARN_SECONDS', 300),
            'backlog_fail_seconds' => (int) env('ESIGN_WEBHOOK_BACKLOG_FAIL_SECONDS', 1800),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Outbound mail (Stage 4, issue #35)
    |--------------------------------------------------------------------------
    |
    | Every transactional message goes through the outbox in
    | App\Domain\Delivery\Mail. The states it records are deliberately
    | distinct: `queued` means the row exists, `sent_to_provider` means a
    | transport accepted the bytes, and only `delivered` — which can arrive
    | only from provider feedback — means a mailbox took it. Nothing in this
    | file lets a logged message be counted as a delivered one; see
    | docs/delivery/mail.md.
    |
    */

    'mail' => [
        // Attempts per message, and the pause before each retry in seconds.
        // The backoff list is consumed positionally; the last value repeats.
        'tries' => (int) env('ESIGN_MAIL_TRIES', 5),

        'backoff' => array_values(array_filter(array_map(
            static fn (string $value): int => (int) trim($value),
            explode(',', (string) env('ESIGN_MAIL_BACKOFF', '60,300,900,3600'))
        ))),

        // Named queue for send jobs, so mail never sits behind sealing work.
        'queue' => env('ESIGN_MAIL_QUEUE', 'mail'),

        /*
        | Brevo posts transactional events to POST /webhooks/mail/brevo. It
        | signs nothing, so the only thing standing between the endpoint and
        | the internet is this shared token, compared with hash_equals. An
        | empty token disables the endpoint rather than opening it.
        */
        'brevo_webhook_token' => env('ESIGN_MAIL_BREVO_WEBHOOK_TOKEN', ''),

        /*
        | SES publishes feedback through SNS. The endpoint checks the topic ARN
        | against this value and then asks an SnsSignatureVerifier to prove the
        | message came from AWS. No verifier is implemented (the
        | aws/aws-sns-message-validator package is not a dependency), so the
        | endpoint currently rejects everything: unverified input never
        | mutates a mail row. See docs/delivery/mail.md.
        */
        'ses_topic_arn' => env('ESIGN_MAIL_SES_TOPIC_ARN', ''),

        // Backlog thresholds for the mail_backlog readiness probe: the age in
        // seconds of the oldest message still `queued`, and the number of
        // messages that reached `failed` in the last 24 hours.
        'backlog_warn_seconds' => (int) env('ESIGN_MAIL_BACKLOG_WARN_SECONDS', 300),
        'backlog_fail_seconds' => (int) env('ESIGN_MAIL_BACKLOG_FAIL_SECONDS', 1800),
        'failed_warn_count' => (int) env('ESIGN_MAIL_FAILED_WARN_COUNT', 1),
        'failed_fail_count' => (int) env('ESIGN_MAIL_FAILED_FAIL_COUNT', 25),
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

    /*
    |--------------------------------------------------------------------------
    | Visual field editor (Stage 2, issue #22)
    |--------------------------------------------------------------------------
    |
    | Prefill variable names the sending context can resolve, offered as
    | suggestions in the editor and checked against a field's
    | `prefill.variable`.
    |
    | Empty by default, and deliberately so. A *template* has no sending
    | context, which is why TemplateService does not run the resolvability
    | check when it stores a version either (docs/preparation/field-schema.md,
    | "Checks that need context"). With the list empty the editor skips the
    | check and says on screen that it skipped it — an omitted check is never
    | reported as a passed one. Validating against an empty list instead would
    | reject every prefill in the document.
    |
    | A deployment whose sending context does have a fixed variable set names
    | it here as a comma-separated list and gets the check back at draft time
    | rather than at send time.
    |
    */

    'preparation' => [
        'prefill_variables' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('ESIGN_PREFILL_VARIABLES', ''))
        ))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Whether the operator actually set the provider URL
    |--------------------------------------------------------------------------
    |
    | `bherila-auth.oauth_client.provider` carries a package default
    | (`bherila`), so it is never empty and cannot answer "did this operator
    | configure a provider?" on its own. `oauth_provider` mirrors the raw
    | variable so esign:bootstrap-owner can tell "unset" from "set to the
    | default's own value". `oauth_provider_url` has no such package default
    | to work around — `bherila-auth.oauth_client.base_url` reads the same
    | `OAUTH_PROVIDER_URL` variable with no fallback — but it is still read
    | through config rather than by calling env() at the call site, which
    | keeps the answer correct under `config:cache`, where env() returns null.
    |
    | Consumed by esign:bootstrap-owner, which must refuse to bind an SSO owner
    | to a provider nobody chose.
    |
    */

    'oauth_provider_url' => env('OAUTH_PROVIDER_URL', ''),

    'oauth_provider' => env('OAUTH_PROVIDER', ''),

];
