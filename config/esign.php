<?php

use App\Domain\Delivery\Outbound\DestinationAllowlist;
use App\Domain\Evidence\Sealing\SealCertificateDirectory;

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

        /*
        | Certificates of key versions this deployment has retired.
        |
        | Sealing always uses the active key above. Verification cannot: an
        | artifact records the key id that sealed it and keeps it forever, so
        | after a rotation this deployment holds documents whose key id is no
        | longer the active one. Those certificates live here, and
        | App\Domain\Evidence\Sealing\SealCertificateDirectory resolves an
        | artifact's recorded key id through this list.
        |
        | Certificates only. Verifying never needs — and must never be given —
        | a retired private key; the private half should already have been
        | destroyed on the schedule the key policy sets.
        |
        | Compact form, comma-separated, fields separated by "|":
        |
        |   ESIGN_SEAL_RETIRED_KEYS="seal-2026-a|/srv/esign-keys/2026-01/seal.crt|/srv/esign-keys/2026-01/chain.crt,seal-2025-a|/srv/esign-keys/2025-01/seal.crt"
        |
        | The chain path is optional. See docs/operations/seal-key-management.md
        | for why this is an environment list rather than a directory scan.
        */
        'retired_keys' => SealCertificateDirectory::parseEnvironment(
            env('ESIGN_SEAL_RETIRED_KEYS')
        ),
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
    | Cron-driven bounded queue worker (Stage 5, issue #39)
    |--------------------------------------------------------------------------
    |
    | Defaults for `esign:queue:work-bounded` (App\Domain\Delivery\Queue\Console\
    | WorkBoundedCommand), the cPanel/shared-hosting substitute for a persistent
    | queue daemon: cron invokes it every minute, it takes a database-backed
    | lease so an overlapping tick never runs a second worker, and it bounds
    | itself with `queue:work --stop-when-empty --max-time --max-jobs`.
    |
    | `lease_ttl` MUST exceed `max_time` plus the longest job's own timeout.
    | `--max-time` only stops queue:work *between* jobs — a job already running
    | when the clock expires is not interrupted (docs/HANDOFF.md section 13) —
    | so a lease that expired at exactly `max_time` would let a second cron
    | tick declare the still-finishing worker stale and start a second one.
    | The gap between `max_time` and `lease_ttl` is the safety margin for
    | "still legitimately working the last job", not slack to be tightened.
    |
    */

    'queue' => [
        'max_time' => (int) env('ESIGN_QUEUE_MAX_TIME', 50),
        'max_jobs' => (int) env('ESIGN_QUEUE_MAX_JOBS', 100),

        // Seconds. Default 15 minutes: comfortably longer than max_time plus
        // the longest sealing job's own timeout, so a live worker's lease
        // never goes stale mid-job.
        'lease_ttl' => (int) env('ESIGN_QUEUE_LEASE_TTL', 900),

        // Seconds between lease heartbeats while the worker runs. Heartbeats
        // are taken between jobs (Illuminate\Queue\Events\Looping), so a
        // single job that runs longer than this never gets an extra
        // heartbeat mid-flight; that is the same "not a hard per-job
        // interrupt" limit as max_time.
        'heartbeat' => (int) env('ESIGN_QUEUE_HEARTBEAT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | cPanel / shared-hosting profile (Stage 5, issue #39)
    |--------------------------------------------------------------------------
    |
    | Read by `esign:doctor`. `web_php_version` names the PHP version the
    | account's web vhost actually runs (e.g. from the ea-phpNN handler in
    | public/.htaccess) so the diagnostic can compare it against the CLI
    | binary it is running under; the two can and do drift on cPanel when the
    | account default PHP differs from the version artisan is invoked with
    | (see htaccess-append.txt). Leave blank to skip that comparison.
    |
    | The memory/time minimums are this diagnostic's own proposal, not a
    | figure stated elsewhere in the repository: nothing in
    | docs/evidence/finalization.md sets a floor, so 512M / 300s is a
    | conservative estimate for sealing a large synthetic PDF and is
    | documented as a proposal in docs/operations/cpanel.md, not a measured
    | requirement.
    |
    */

    'cpanel' => [
        'web_php_version' => env('ESIGN_CPANEL_WEB_PHP_VERSION', ''),
        'min_memory_bytes' => (int) env('ESIGN_CPANEL_MIN_MEMORY_BYTES', 512 * 1024 * 1024),
        'min_execution_seconds' => (int) env('ESIGN_CPANEL_MIN_EXECUTION_SECONDS', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Signing lifecycle timing (Stage 4, issues #34 and #35)
    |--------------------------------------------------------------------------
    |
    | Read by the scheduled commands in App\Domain\Delivery\Events. Both are
    | measured from a fact on the row rather than from when the scheduler last
    | ran, so an extra run of `esign:signing:remind` sends nothing extra. See
    | docs/delivery/envelope-events.md.
    |
    | These two keys used to live in a second, separate 'signing' => [...]
    | array further down this file. PHP array literals silently let a later
    | key win, so that second array was overwriting this one outright and
    | reminder_after_hours/reminder_interval_hours could never be set from
    | the environment in a real deployment (only test suites that call
    | Config::set() directly happened to bypass the bug). Both groups are
    | merged into the one 'signing' array below; see the guest-access
    | doc-comment there for the rest of the keys.
    |
    */

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
    | Native API (Stage 5, issue #41)
    |--------------------------------------------------------------------------
    |
    | Failed service-credential authentications a single client address may
    | make in one minute before the surface stops answering it.
    |
    | Failures only. A working integration never reaches this ceiling, because
    | a successful authentication is not counted, so the limit can be low
    | enough to matter without a legitimate caller's throughput depending on
    | it. What it bounds is an unauthenticated caller hammering the surface:
    | each attempt costs a query and a warning log line, and nothing else in
    | the stack says no (docs/security/review-2026-09.md finding A-1).
    |
    | It is not what stops a secret being guessed — 43 characters from a
    | 36-character alphabet does that — and it is not a substitute for a rate
    | limit at the edge. Set to 0 to switch it off where an edge proxy already
    | owns this.
    |
    */

    'api' => [
        'auth_failures_per_minute' => (int) env('ESIGN_API_AUTH_FAILURES_PER_MINUTE', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Response security headers (Stage 5, issue #41)
    |--------------------------------------------------------------------------
    |
    | `Strict-Transport-Security` max-age, in seconds, emitted by
    | App\Http\Middleware\SecurityHeaders on requests that arrived over TLS.
    | Zero switches the header off, which is the right setting where a reverse
    | proxy already owns it. `includeSubDomains` is deliberately not offered
    | here; see the middleware's docblock.
    |
    */

    'security' => [
        'hsts_max_age' => (int) env('ESIGN_HSTS_MAX_AGE', 31_536_000),
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

    /*
    |--------------------------------------------------------------------------
    | Guest signing access and capture (Stage 3, issues #25 and #26)
    |--------------------------------------------------------------------------
    |
    | Recipients are not application users. They arrive holding one opaque
    | invitation credential, exchange it for a scoped session in an explicit
    | POST, and act only inside that session. Everything below is the
    | deployment's half of that arrangement; the rules themselves live in
    | App\Domain\Signing\Sessions and App\Domain\Signing\Capture, and the
    | flow is described in docs/signing/guest-access.md.
    |
    */

    'signing' => [

        // Hours after an invitation was sent before the first reminder. Measured
        // from `envelope_recipients.invited_at`.
        'reminder_after_hours' => (int) env('ESIGN_SIGNING_REMINDER_AFTER_HOURS', 72),

        // Minimum hours between two reminders to the same person.
        'reminder_interval_hours' => (int) env('ESIGN_SIGNING_REMINDER_INTERVAL_HOURS', 24),

        // How long an invitation credential stays usable, from the moment it is
        // issued. Seven days by default: long enough to survive a weekend and an
        // out-of-office, short enough that a forwarded mail from last quarter is
        // not a signing credential. Reissuing revokes the previous one.
        'invitation_ttl_hours' => (int) env('ESIGN_SIGNING_INVITATION_TTL_HOURS', 168),

        // A signing session's lifetime, refreshed on each authorized request
        // (a sliding window). Two hours is a long review of a long contract; it
        // is not an ambient login, and nothing renews it after the browser is
        // closed because the cookie is a session cookie.
        'session_ttl_minutes' => (int) env('ESIGN_SIGNING_SESSION_TTL_MINUTES', 120),

        // Deployment-wide default for mailbox OTP on top of the link. A
        // workspace may turn it on for everything it sends, and one envelope may
        // override both. Resolution is envelope, then workspace, then this.
        //
        // Off by default and honestly labelled: an emailed code demonstrates
        // continued access to the same mailbox the link went to. It is a second
        // check on the same factor, not a second factor, and it is never an
        // eIDAS advanced or qualified signature (docs/HANDOFF.md section 9).
        'require_otp' => filter_var(env('ESIGN_SIGNING_REQUIRE_OTP', false), FILTER_VALIDATE_BOOLEAN),

        'otp' => [
            'length' => 6,
            'ttl_minutes' => (int) env('ESIGN_SIGNING_OTP_TTL_MINUTES', 10),

            // Wrong codes tolerated before the challenge is burned and a new one
            // has to be requested. Counted on the challenge, so guessing cannot
            // be spread across parallel requests.
            'max_attempts' => (int) env('ESIGN_SIGNING_OTP_MAX_ATTEMPTS', 5),

            // Issuance limits, applied per destination address and per client
            // address through Laravel's RateLimiter. The address limit stops a
            // mailbox being used as a bullhorn; the IP limit stops one client
            // enumerating recipients.
            'per_address_per_hour' => (int) env('ESIGN_SIGNING_OTP_PER_ADDRESS_PER_HOUR', 5),
            'per_ip_per_hour' => (int) env('ESIGN_SIGNING_OTP_PER_IP_PER_HOUR', 20),

            // Verification attempts accepted from one client address per hour,
            // independent of which challenge they are aimed at.
            'verify_per_ip_per_hour' => (int) env('ESIGN_SIGNING_OTP_VERIFY_PER_IP_PER_HOUR', 30),
        ],

        // Attempts to exchange an invitation for a session, per client address
        // per hour. A link that is being brute-forced is not a link anybody has.
        'start_per_ip_per_hour' => (int) env('ESIGN_SIGNING_START_PER_IP_PER_HOUR', 30),

        // The consent policy version the text in resources/views/signing/consent.md
        // states. An envelope snapshots its own version at creation, and the
        // attestation records that snapshot; this value only lets the signing page
        // say whether the text it is showing is the text that version named. When
        // the two differ the page says so rather than implying otherwise.
        'consent_policy_version' => (string) env('ESIGN_CONSENT_POLICY_VERSION', '2026-09-01'),

        // Captured signature and initials images. A submitted image is decoded
        // with GD, measured, and re-encoded as a PNG before it is stored, so what
        // ends up on the agreement is bytes this application produced. SVG, HTML,
        // and remote URLs are refused outright rather than sanitized.
        'max_signature_image_bytes' => (int) env('ESIGN_SIGNING_MAX_SIGNATURE_IMAGE_BYTES', 204_800),
        'max_signature_image_width' => (int) env('ESIGN_SIGNING_MAX_SIGNATURE_IMAGE_WIDTH', 2_000),
        'max_signature_image_height' => (int) env('ESIGN_SIGNING_MAX_SIGNATURE_IMAGE_HEIGHT', 800),

        // Hosts a `?return=` destination may point at once signing finishes.
        // Comma-separated hostnames, compared exactly and case-insensitively
        // against the URL's host. Anything else is ignored — not rejected with an
        // error the caller can probe, and never reflected back into a redirect.
        // Empty means no return destination is ever honoured, which is the safe
        // default for a deployment that has not thought about it.
        'return_url_allowlist' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('ESIGN_SIGNING_RETURN_URL_ALLOWLIST', ''))
        ))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Finalization (Stage 3, issue #94)
    |--------------------------------------------------------------------------
    |
    | Finalization is started by the last acceptance
    | (App\Domain\Evidence\Finalization\FinalizationTrigger), which queues
    | FinalizeEnvelope after the transition commits. This key belongs to the
    | recovery pass behind it, `esign:finalization:resume`, scheduled every five
    | minutes in routes/console.php, and to the `finalization_backlog` readiness
    | probe, which counts exactly the set that command would re-dispatch.
    |
    | How long an envelope may sit in `finalizing` with no worker on it before
    | the sweep queues it again. It is a floor on the wait, not a timeout: a run
    | that started inside the window is a worker still sealing, and sealing a
    | large document with a timestamp round trip is legitimately slow. Ten
    | minutes is comfortably longer than any observed attempt and short enough
    | that a lost job costs a signer minutes rather than a day.
    |
    | Raising it delays recovery; lowering it below the slowest real sealing time
    | starts allocating competing generations, which is safe (the publishing
    | compare-and-swap lets exactly one attempt publish) but wasteful.
    |
    */

    'finalization' => [
        'resume_after_minutes' => (int) env('ESIGN_FINALIZATION_RESUME_AFTER_MINUTES', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention, legal hold, and deletion (Stage 5, issue #40)
    |--------------------------------------------------------------------------
    |
    | Three policies, deliberately separate, because they protect different
    | things and an operator has to be able to set them independently
    | (docs/HANDOFF.md section 12). One combined "retention days" would mean
    | choosing between keeping authentication logs too long and deleting
    | executed agreements too soon. The runbook is docs/operations/retention.md.
    |
    | `esign:retention:run` reads all three. Nothing here deletes anything on
    | its own; a policy is only applied when that command runs, and a legal
    | hold overrides every one of them.
    |
    */

    'retention' => [

        /*
        | Authentication audit rows (`auth_audit_log`, owned by auth-laravel).
        |
        | 400 days rather than 365 so a year-on-year comparison and an annual
        | review still have the previous cycle to look at. These rows are
        | operational security history, not evidence about an agreement: no
        | attestation, digest, or artifact depends on one, which is why this is
        | the only policy with a finite default.
        */
        'auth_logs_days' => (int) env('ESIGN_RETENTION_AUTH_LOGS_DAYS', 400),

        /*
        | Drafts that were never sent, and uploaded documents that no envelope
        | and no template version references.
        |
        | Nobody signed anything, so there is nothing to retain and nothing to
        | prove; what is left is an uploaded PDF sitting on a private disk for
        | no reason. Ninety days is long enough that a sender who started a
        | draft before a quarter-end break still finds it.
        |
        | "Never sent" is `state = draft` with a null `sent_at`, not "no
        | recipient has signed". A cancelled or expired envelope went out to
        | somebody and is history rather than an abandoned draft.
        */
        'abandoned_drafts_days' => (int) env('ESIGN_RETENTION_ABANDONED_DRAFTS_DAYS', 90),

        /*
        | Executed agreements. Null means never delete automatically, and that
        | is the shipped default.
        |
        | docs/HANDOFF.md section 12: "Default to no automatic deletion of
        | executed documents until an operator has configured a reviewed
        | policy." A number here is a decision about how long this deployment
        | is required to be able to produce a signed agreement, and only the
        | operator knows what that requirement is — it comes from the
        | jurisdictions the agreements were signed under and from whatever
        | contractual retention the counterparties agreed to, neither of which
        | a default can guess. An unset value is therefore not an oversight to
        | be filled in with something plausible; it is the safe answer.
        |
        | Set ESIGN_RETENTION_EXECUTED_DOCUMENTS_DAYS to a positive integer to
        | turn the policy on. An empty string, `null`, `0`, or a negative value
        | all mean "not configured", so a half-finished .env edit cannot switch
        | deletion on by accident.
        */
        'executed_documents_days' => env('ESIGN_RETENTION_EXECUTED_DOCUMENTS_DAYS'),

        /*
        | How long a soft-deleted envelope keeps its bytes.
        |
        | `esign:retention:run` soft-deletes the envelope and records every
        | artifact digest it is scheduled to destroy;
        | `esign:retention:purge-blobs` removes the objects, and only for rows
        | that have been soft-deleted for longer than this. The gap is the
        | window in which a retention decision made in error is still one
        | UPDATE away from being undone, which is the difference between a
        | policy and an accident.
        */
        'purge_grace_days' => (int) env('ESIGN_RETENTION_PURGE_GRACE_DAYS', 30),

        /*
        | Age, in days, at which the `artifact_integrity` readiness probe stops
        | trusting the last `esign:artifacts:verify` run. Eight, because
        | routes/console.php runs the verification weekly: seven would warn on
        | every ordinary schedule drift, and anything much larger would let a
        | verification that stopped running go unnoticed for a fortnight.
        */
        'verification_warn_days' => (int) env('ESIGN_RETENTION_VERIFICATION_WARN_DAYS', 8),

        /*
        | Where `esign:backup:manifest` writes when `--path` is not given, and
        | where `esign:restore:verify` looks when `--manifest` is not given.
        | Relative paths resolve against the application base path.
        */
        'manifest_path' => env('ESIGN_BACKUP_MANIFEST_PATH', 'storage/app/backups/manifest.json'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Restore drill (Stage 5, issue #40)
    |--------------------------------------------------------------------------
    |
    | Set ESIGN_RESTORE_DRILL=1 in the throwaway environment a backup is
    | restored into. It does two things, and both matter:
    |
    |  - `esign:restore:verify` refuses to run without it, so the drill cannot
    |    be pointed at a live instance by a mistyped host.
    |  - Outbound mail and webhook delivery refuse to send. A restored database
    |    holds real recipient addresses and real endpoint URLs with queued work
    |    against both; a worker started in that copy would re-invite people to
    |    agreements they already signed and re-POST completion events to a
    |    production consumer. Nothing about the copy tells it that it is a copy,
    |    so this variable does.
    |
    | It is a refusal rather than a redirect to a log mailer: a suppressed send
    | that looks like a successful one teaches the drill nothing.
    |
    */

    'restore_drill' => filter_var(
        env('ESIGN_RESTORE_DRILL', false),
        FILTER_VALIDATE_BOOLEAN
    ),

];
