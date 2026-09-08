# Release gates: what proves each one

Every row of [`docs/HANDOFF.md`](../HANDOFF.md) section 14, mapped to the test or CI job that
establishes it — or to an explicit **not yet proven** with the issue that owns it.

Written for issue [#41](https://github.com/bherila/e-sign/issues/41), alongside
[`docs/assurance.md`](../assurance.md) and
[`docs/security/review-2026-09.md`](review-2026-09.md).

**How to read it.** A gate is *proven* only when something in CI fails if it stops being true.
A gate that is upheld by careful code and no assertion is **not proven**, and is listed that way
however good the code is — that distinction is the whole point of the document. Where a gate is
partly proven, the table says which part.

**State of the union.** Of fifteen gates: **ten proven** (1, 2, 3, 4, 5, 6, 7, 8, 10, 12), **four
partly proven** (9, 11, 14, 15), **one not yet proven** (13 migration). Gate 2 moved on
2026-09-08 when the `pades-profile` job landed; see [`docs/stage0/pades-profile.md`](../stage0/pades-profile.md).
Nothing here should be read as a claim that this product is ready for a first production use; `docs/HANDOFF.md`'s status line and `docs/assurance.md` say what it is.

---

## What runs, and when

`.github/workflows/ci.yml` on every pull request and every push to `main`, with a `changes` job
gating the rest by touched path:

| Job | Runs when | What it does |
|---|---|---|
| `test` | frontend or backend changed | PHP 8.4 **and** 8.5. Frontend: `tsc --noEmit`, `eslint`, `jest`. Backend: `pint --parallel --test`, then `composer test` (`artisan test --parallel`, the `Unit`, `Feature` and `EndToEnd` suites on in-memory SQLite). |
| `database` | backend changed | `php artisan migrate --force` and the feature suite against disposable **MySQL 8.4**, **MariaDB 11.4**, and **MariaDB 10.6** service containers, PHP 8.5. |
| `licenses` | dependency manifests changed | Production-only installs, `composer licenses` + `pnpm licenses`, checked against an allowlist; builds and uploads an SBOM. Not a vulnerability check. |
| `audit` | dependency manifests changed, **and weekly on a schedule** | `composer audit` + `pnpm audit`, checked against `.audit-allowlist.json` by `scripts/check-audit.php`. Scheduled as well as diff-gated, because an advisory database gains entries after a merge rather than when a lock file changes. |
| `image` | backend, frontend, or docker changed | Builds the production image and smoke-tests the **web** role (`/up`, then `esign-healthcheck`) and **role dispatch** (`artisan`, `worker --stop-when-empty`), with `APP_ENV=production APP_DEBUG=false`. |
| `validation` | backend or docker changed | PHP 8.4, installs pyHanko 0.37 + `pyhanko-cli`, runs `scripts/validate-seal.sh --regenerate` over every committed artifact against `tests/Fixtures/validation/manifest.tsv`. Uploads the validator output. |
| `pades-profile` | backend or docker changed | Temurin JDK 21, runs `scripts/validate-pades-profile.sh`: European Commission **DSS 6.5** over the committed artifact bytes (no resealing, no PHP), matched against `tests/Fixtures/validation/pades-profile-manifest.tsv` on level *and* conclusion. A separate job from `validation` on purpose, so a JVM or Maven Central problem cannot mask a pyHanko regression. Uploads DSS's reports. |
| `result` | always | Aggregating gate; fails if any of the above failed or was cancelled. |

`deploy.yml` (cPanel rsync, off until `DEPLOY_ENABLED`) runs the suite, deploys, and then
`curl --fail "$SITE_URL/up"`. `publish-image.yml` and `release-bundle.yml` publish artifacts and
attach an SBOM.

Local gate, from `TESTING.AGENTS.md`: `./vendor/bin/pint --parallel --test && composer test`, and
for frontend changes `pnpm run type-check && pnpm run lint && pnpm run test && pnpm run build`.

---

## The gates

### 1. Crypto and trust — **proven**

> Positive artifacts validate with known configured trust; modified content, forged CMS, wrong
> keys, truncated PDFs, and unexpected revisions fail. An embedded certificate alone is not
> automatically trusted.

| Proof | Where |
|---|---|
| Eight committed artifacts validated by pyHanko, each against a **required outcome** in a manifest — matched on the stated verdict, not the exit status | `validation` CI job, `scripts/validate-seal.sh`, `tests/Fixtures/validation/manifest.tsv`, `tests/Feature/Evidence/ValidationFixturesTest.php` |
| Modified content, over-claimed `/ByteRange`, truncation, an appended incremental revision, a spliced foreign CMS, and an untrusted signer each required to fail | `tests/Feature/Evidence/SealedArtifactTamperingTest.php` (9 cases), same manifest |
| An untrusted TSA required to fail on TSA trust alone, so "signed" is not confused with "trusted" | `sealed-b-t-untrusted-tsa.pdf`, required `invalid` |
| The sealer re-reads its own output and refuses an artifact below the requested level, or signed by other material | `tests/Feature/Evidence/TcLibPdfSealerTest.php` |
| Unusable material — expired, mismatched key, wrong passphrase, unsupported digest, absent — each a typed exception | `tests/Unit/Evidence/SealMaterialTest.php` |

### 2. PAdES profile — **proven**

> Local DSS/profile checks and explicit requirement fixtures establish the claimed output level.
> A generic "signature valid" result alone is insufficient.

| Part | Status |
|---|---|
| Cryptographic and trust validation of every artifact | proven — `validation` job |
| In-process assertion that the produced artifact reaches the requested level | proven — `ArtifactValidator`, and `EnvelopeFinalizer` checks it independently of the sealer |
| **Profile-level conformance against ETSI EN 319 142-1** | **proven** — `pades-profile` job |

| Proof | Where |
|---|---|
| European Commission **DSS 6.5** reports `PAdES_BASELINE_B` for both B-B artifacts and `PAdES_BASELINE_T` for both B-T artifacts, matched **exactly** against a manifest on every run | `pades-profile` CI job, `scripts/validate-pades-profile.sh`, `tests/Fixtures/validation/pades-profile-manifest.tsv` |
| The level is DSS's structural verdict, not a restatement of "the signature verified": a signature that were a bare PKCS#7 rather than a conformant baseline signature reports `PKCS7_B` or `PDF_NOT_ETSI` instead | `tools/dss/src/main/java/PadesProfileCheck.java`; the reported levels are identical under DSS's stock policy and the pinned one, so no policy edit produces them |
| DSS's AdES conclusion is matched exactly too, per artifact, so the negatives cannot pass by reporting the right level with the wrong verdict | same manifest, `conclusion` column |
| Each signature timestamp's own conclusion is matched separately, so "B-T" and "the timestamp is trustworthy" cannot collapse into one answer | same manifest, `timestamps` column |
| The validation policy is DSS's own default with two edits, each marked inline and diffable against the vendor's original | `tools/dss/validation-policy.xml` |

pyHanko's own documentation states that its ordinary validation is **not a complete structural
PAdES-profile conformance check**, which is why this second job exists rather than another flag
on the first. The two validators share no code, run in separate jobs, and are **not** tuned to
agree: `sealed-b-t-untrusted-tsa.pdf` is required to be INVALID for pyHanko and `TOTAL_PASSED`
for DSS, and the reason is written down.

**Caveats, not gaps in this gate.** Both runs are anchored on a throwaway fixture root, so
neither says anything about trust in the real world. Revocation is unchecked in both, because a
self-issued chain publishes nothing to check (gap 7 in
[`docs/stage0/sealing.md`](../stage0/sealing.md)) — the DSS policy's edit A is the same
relaxation pyHanko gets from `--no-revocation-check`, and must be reverted with it. A
`PAdES_BASELINE_B` verdict is a statement about **format conformance**, never about eIDAS
qualified or advanced status; nothing here claims one.

**Finding, recorded rather than tuned away:** under DSS's *stock* policy,
`negative-incremental-update.pdf` receives the same AdES conclusion as a clean artifact. DSS
sees the appended revision and warns about it, but its `UndefinedChanges` constraint ships at
`WARN`. This service is stricter, so the pinned policy raises it to `FAIL` — the same shape as
the pyHanko finding already in `sealing.md` §5, now confirmed on a second validator.

**Full findings:** [`docs/stage0/pades-profile.md`](../stage0/pades-profile.md).

### 3. PDF fidelity — **proven**

> Rotation, CropBox offsets, mixed sizes, Unicode, forms/annotations, xref streams, scanned
> pages, and unsupported encrypted/already-signed inputs. Preview and final geometry agree.

Synthetic fixtures exist for each named case (`tests/Fixtures/pdf/`: `rotated-pages`,
`cropbox-offset`, `multi-page-mixed-size`, `unicode-embedded-font`, `form-fields-annotations`,
`xref-stream`, `object-stream`, `scanned-image-page`, `user-unit`, `encrypted-aes128`,
`already-signed`, `xfa-form`, `embedded-file`, `javascript-action`), and are exercised by
`tests/Feature/Preparation/PdfImportFidelityTest.php`, `PdfPreflightTest.php`,
`PdfOverlayRenderingTest.php`, and `PdfAnchorResolutionTest.php`. Preview/final geometry agreement
is pinned by `tests/Unit/Preparation/Geometry/` and `resources/js/components/editor/PageTransform.test.ts`
consuming the same page-geometry document.

**Closed (issue #88):** the aggregate decompression budget
(`esign.documents.max_decompressed_bytes`) and the object ceiling charged during parsing now
exist, with a fixture corpus for both — `tests/Fixtures/pdf/bombs/` (`single-stream-bomb`,
`many-small-streams-bomb`, `deep-nesting-bomb`, `object-count-bomb`, and the
`large-legitimate-control` that must still pass), generated by
`tests/Fixtures/pdf/generate-bombs.php` and exercised by
`tests/Feature/Preparation/PdfPreflightLimitsTest.php`. See
[`review-2026-09.md`](review-2026-09.md) U-1 and U-2, including the residuals that remain.

### 4. JSON / editor — **proven**

> Round trip without coordinate drift; numeric/keyboard editing; recipient ownership;
> unsupported/missing/ambiguous values fail clearly.

`tests/Unit/Preparation/Schema/FieldSchemaRoundTripTest.php` (no drift),
`FieldSchemaValidatorTest.php` and `FieldSchemaContractTest.php` (clear failures),
`tests/Feature/Preparation/FieldEditorPageTest.php` (17 cases), and the Jest suites
`editorReducer.test.ts`, `FieldEditor.test.tsx`, `FieldTable.test.tsx`, `PageTransform.test.ts`,
`fieldSchema.test.ts`, `fieldSchemaContract.test.ts` — the last of which asserts the TypeScript
and PHP schema contracts agree.

### 5. State machine — **proven**

> Wrong-order, duplicate, stale, cancelled/expired, concurrent signer, and finalize/cancel races
> preserve invariants. Required fields and consent cannot be bypassed.

`EnvelopeTransitionMatrixTest` attempts all 79 illegal transitions and asserts the code table
matches `docs/signing/state-machine.md`. `EnvelopeRaceTest` covers cancel-vs-signature in both
directions, complete-vs-fail finalization, expiry losing to a completed finalization, and two
parallel signers. `EnvelopeSigningOrderTest` covers wrong order, stage activation, a signed
recipient signing again in a new session, and decline. `EnvelopeAcceptanceTest` covers stale
material and stale version in both directions, consent substitution, required fields, and — new
in this branch — a required checkbox stored as `false`, a required text field of whitespace, and
acceptance after `expires_at`.

Two residuals are open and named in [`review-2026-09.md`](review-2026-09.md): S-5 (`updateDraft`
races `send` without a lock) and S-6 (a genuinely concurrent double-accept reports a stale
review rather than a replay).

### 6. Artifact durability — **proven**

> Crash before upload, after upload, and around the final DB commit never emits an invalid
> completion or loses the selected final bytes.

`tests/Feature/Evidence/EnvelopeFinalizationTest.php` is the gate: crash before upload, crash
after upload with republish, two concurrent attempts, an envelope that leaves `finalizing`
mid-run, B-T failing closed, a wrong-state attempt recording nothing, and — with a shared spy
log — that `markCompleted` runs **after** the digest read-back. It also greps `app/` for the word
`etag` and fails if it appears.

`tests/EndToEnd/WorkerInterruptionTest.php` adds the consequence a receiver sees: after a worker
is killed inside the publishing transaction and the run is retried, there is exactly **one**
logical `signing_request.completed` and the consumer processed it exactly once. Two would be
worse than none — a receiver that files an executed agreement twice has two of them, and
deduplicating on the event id cannot help when the ids differ because they really are two
events.

**One hole this suite opened is now closed.** `tests/EndToEnd/` found that nothing in `app/`
dispatched `FinalizeEnvelope`, so an envelope reaching `finalizing` waited for a worker nobody
had told ([#94](https://github.com/bherila/e-sign/issues/94)). `FinalizationTrigger` dispatches
it after the last acceptance commits, `esign:finalization:resume` re-dispatches a job that was
lost, and the `finalization_backlog` probe counts the same set — `FinalizationTriggerTest`,
`FinalizationResumeCommandTest`, `FinalizationBacklogProbeTest`, and the end-to-end harness,
which now asserts the application queued the job instead of queueing it itself.

**One hole is recorded rather than closed:** `publish()` does not re-check that its uploaded
objects still exist ([`review-2026-09.md`](review-2026-09.md) B-2). The window is now bounded by
a one-hour floor on the staging pruner's `--older-than`, which is a mitigation and not the fix.

### 7. Webhooks — **proven**

> Raw-body signatures, clock window, secret rotation, stable event IDs, retries, duplicate
> delivery, timeout, lost response, receiver failure, and reordering are tested.

`tests/Feature/Delivery/Webhooks/DeliverWebhookTest.php` covers the signature over exact bytes, a
fresh timestamp per attempt with a stable event id, rotation overlap and expiry, the retry
schedule and exhaustion with auto-disable, 4xx vs 5xx vs 408/429, a timeout recorded without a
status, a credential-echoing body redacted, truncation, an endpoint disabled after queueing, an
already-settled attempt never re-sent, a destination that starts resolving internally refused at
send time, and replay as a new attempt of the same event. `OutboxWriterTest` covers atomic
outbox writes; `WebhookSignerTest` and `RetryScheduleTest` cover the primitives;
`WebhookTransportOptionsTest` pins redirects off at every layer and the connection pinned.
`tests/Unit/Delivery/DestinationPolicyTest.php` is the SSRF matrix, extended in this branch with
IPv4-translated, multicast, and non-ASCII-host cases.

`tests/EndToEnd/WebhookReliabilityTest.php` re-runs the same failure modes against a **real
receiver** on a route registered inside the test, which verifies the signature itself with an
independent HMAC implementation and keeps its own inbox: a 500 then success, a lost response, a
request that never arrives, three events handed over newest-first, a replay, a rotation overlap
in both directions, and a body that cannot be verified answered 400 rather than 500. The
difference from `DeliverWebhookTest` is that "the retry carries a fresh timestamp" is now
asserted by something that would reject the request if it did not.

Replay protection *inside* the receiver's window is explicitly the receiver's, and
`docs/delivery/webhooks.md` says so. Every idempotence assertion in the end-to-end suite is
therefore an assertion about the synthetic consumer's own inbox, not a claim that the sender
deduplicates.

### 8. Isolation — **proven**

> Cross-workspace access through IDs, aliases, JSON, downloads, queues, service keys, editor
> routes, and webhook replay is denied.

| Surface | Proof |
|---|---|
| IDs, slugs, autoincrement ids, route binding, memberships, service credentials | `tests/Feature/Identity/CrossWorkspaceIsolationTest.php` (13 cases) |
| Native API: envelopes, templates and versions, a body-supplied `document_id`, webhook endpoints, events, `/me` | `tests/Feature/Integration/Native/NativeApiIsolationTest.php` |
| Scope matrix over the whole `/api/v1` route surface, each route with a credential holding every *other* scope | `tests/Feature/Integration/Native/NativeApiAuthTest.php` |
| Documents, templates, and editor routes | `DocumentHttpTest`, `TemplateHttpTest`, `FieldEditorPageTest` — each asserts another workspace's resource is a 404 |
| Guest sessions scoped to one envelope | `tests/Feature/Signing/GuestSigningSessionScopeTest.php` |
| The Firma compatibility facade: another workspace's id on every endpoint, the profile scope alone granting no reads, a read-only credential refused a cancel, the template scope enforced on both create routes | `tests/Feature/Integration/Firma/FirmaAuthTest.php` (12 cases) — merged as [#33](https://github.com/bherila/e-sign/issues/33) |
| Artifact downloads: another tenant's artifact id is a 404, and an unpublished artifact is invisible and unfetchable | `tests/Feature/Integration/Native/NativeApiFinalizedArtifactTest.php`; `ArtifactLocator` is bound to `FinalizedArtifactLocator` |
| A facade download link is a signed capability naming one document of one signing request, and no disk name or object path appears in it | `tests/Feature/Integration/Firma/FirmaDownloadTest.php`, `tests/EndToEnd/StoragePortabilityTest.php` |

`NativeApiAuthTest::scopedRoutes()` is a hand-maintained list. `OpenApiContractTest` guards
route↔document drift bidirectionally, but nothing guards route↔scope-matrix drift, so a new route
added with no scope would slip past unless somebody also adds a row. Worth a structural
assertion.

### 9. SSO — **partly proven**

> Real browser callback flow; PKCE/state failures; issuer/subject binding; bootstrap; disabled
> grants; local roles; no shared parent-domain sessions.

| Part | Status |
|---|---|
| Authorization-code start with state and PKCE | proven — `OAuthLoginTest` |
| Mismatched state, absent session state, rejected code, unusable identity response, replayed callback, disabled account | proven — `OAuthLoginTest` (6 cases) |
| Issuer + subject binding, and that email is never an account-linking key | proven — `OAuthLoginTest::test_a_second_subject_reporting_the_same_address_becomes_a_second_account`, `…_a_local_account_with_the_same_address_is_not_adopted` |
| First login is never an administrator; bootstrap is an explicit command | proven — `OAuthLoginTest::test_an_unknown_subject_is_admitted_and_given_no_workspace_at_all`, `BootstrapOwnerCommandTest` |
| Local roles and permissions | proven — `WorkspacePolicyTest`, `WorkspaceRoleTest`, `MembershipRemovalTest` |
| No shared parent-domain session | proven — `tests/Feature/Auth/SessionCookieTest.php`, `config/session.php` (`domain` null, distinct cookie name) |
| **A real browser callback against a real issuer** | **not proven.** Every test above drives the callback through the framework's HTTP kernel with a stubbed provider. Issue [#44](https://github.com/bherila/e-sign/issues/44) owns registering the client and completing an actual browser login. |
| **Re-checking disablement and entitlement within a documented revocation window** | **not proven** — the contract is documented, the periodic re-check is not implemented |

### 10. Guest signing — **proven**

> Mail-preview GETs harmless; token expiry/rotation; OTP rate limits; no unauthorized use of
> public recipient IDs; CSRF and return-URL protections.

`HarmlessGetTest` fetches every GET twice and diffs the envelope, every recipient, every field
value, every attestation, every invitation, every OTP challenge, and the outbox — and pins the
list of GET route names so a fifth forces a decision. `GuestCredentialsTest` and
`InvitationIssuanceTest` cover entropy, hashing, expiry, one-shot consumption, and
revoke-on-reissue. `GuestSigningOtpTest` covers the attempt ceiling, burning, expiry, and both
issuance limiters. `LegacyRecipientResolverTest` covers the identical-response property, the
per-client limit, purpose separation, and — new in this branch — that exhausting the per-address
ceiling does not confirm the address. `SigningSecurityHeadersTest` asserts the headers, the
policy, that every POST gathers CSRF protection, and with `Log::spy()` that a full sequence logs
nothing at all. `ReturnUrlPolicyTest` covers the allowlist.

One accepted residual: the resolver's `GET` is an unthrottled existence oracle against an 80-bit
identifier ([`review-2026-09.md`](review-2026-09.md) G-3).

### 11. Runtime portability — **partly proven**

> Same supported functional flow on PHP baseline with MySQL+cron and without a remote signing
> service; Docker worker variant also tested.

| Part | Status |
|---|---|
| PHP 8.4 **and** 8.5 | proven — the `test` job matrix |
| MySQL 8.4, MariaDB 11.4, MariaDB 10.6: migrations and the whole feature suite | proven — the `database` job |
| Cron-driven bounded queue work with a database lease | proven — `WorkBoundedCommandTest`, `WorkerLeaseManagerTest` |
| No remote signing service in the path | proven by construction — sealing is in-process; the only outbound calls are the TSA and webhooks, both through `DestinationPolicy` |
| Docker image builds; web role answers `/up` and `esign-healthcheck`; role dispatch works | proven — the `image` job |
| cPanel runtime diagnostics | proven — `DoctorCommandTest`, `PhpRuntimeProbeTest`, `WebPhpVersionProbeTest`, `ResourceLimitsProbeTest`, `WritablePathsProbeTest` |
| The functional flow such a smoke test would run — authenticate with a service credential, prepare, invite, sign, seal, download, validate, deliver a **verified** webhook, and recover after a worker interruption — end to end, on both PHP versions and all three database engines | proven — `tests/EndToEnd/` (25 cases), which runs inside the `test` and `database` jobs. Finalization is now started by the application rather than by the harness ([#94](https://github.com/bherila/e-sign/issues/94)); the suite asserts the job was queued and then runs it, as a worker would. |
| **That same flow on a deployment profile** | **not proven.** The `image` job checks that the container starts and answers a health probe; `docs/HANDOFF.md` section 13 is explicit that *"a deployment is not 'working' merely because its home page loads"*, and a suite inside the test process is not a deployment. Issue [#38](https://github.com/bherila/e-sign/issues/38) for Docker; the cPanel path has `scripts/build-release.sh` and a runbook but no automated smoke test. |
| **A host without pcntl / process-spawning** | **not proven** — claimed support, no CI entry |

### 12. Independence — **proven**

> Block Firma/DocuSign hosts: new workflows still complete. Use local SMTP/storage configuration
> to prove Brevo/Garage are swappable. No CDN/analytics is required.

| Part | Status |
|---|---|
| No third-party origin on the signing surface, asserted on the emitted header and on the rendered page | proven — `SigningSecurityHeadersTest::test_the_signing_page_loads_nothing_from_another_origin`, `…_the_global_analytics_policy_does_not_reach_a_signing_page` |
| No third-party origin **anywhere** | proven, new in this branch — `SecurityHeadersTest::test_the_emitted_policy_is_the_configured_one_and_admits_no_third_party_origin` asserts the emitted policy contains no `https://` at all |
| PDF.js served locally | proven — bundled worker and copied runtime resources; the policy admits no CDN |
| Storage is swappable | proven — the whole suite runs on the local driver via `Storage::fake`; `config/filesystems.php` selects s3 by configuration and no code branches on the driver |
| The same publication runs on a **second, non-local adapter**, with identical digests and identical bytes read back through `get()` and `readStream()`, and the retained original byte-identical across both | proven, new in this branch — `tests/EndToEnd/StoragePortabilityTest.php` against `InMemoryObjectStore`, a flat key space with no directories |
| Mail is swappable | proven — the suite runs on the array mailer; `ProductionMailerGuard` refuses a `log` mailer in production rather than counting it as delivery |
| The invitation the array mailer produced carries a working signing link, and reaches `sent_to_provider` rather than `delivered` | proven, new in this branch — `tests/EndToEnd/IndependenceTest.php`, which then drives the guest flow from exactly that link |
| **A run with Firma/DocuSign hosts blocked, in which new workflows still complete** | **proven, new in this branch** — `tests/EndToEnd/` runs entirely under `Http::preventStrayRequests()` with one fake matching the synthetic consumer's own endpoint. Firma hosts, DocuSign hosts, a CDN and an analytics beacon are each asserted to raise; a complete workflow then runs under the same fence and the only URL on the wire is the consumer's callback. |

**Caveat, not a gap in this gate:** the fence is at the *process* level, not the network level. It
cannot see a socket opened outside the HTTP client, which in this application is exactly one
thing — `HttpTimestampAuthority`, which reaches the network through Guzzle directly. That is why
the end-to-end suite binds a refusing timestamp authority rather than relying on the fence, and
why B-T is not exercised there. A CI job with egress blocked at the network layer would be a
strictly stronger statement and does not exist.

### 13. Migration — **not yet proven**

> Existing Firma requests remain on Firma; imported executed PDFs are byte-preserved; new
> self-hosted requests reconcile and retain all four artifact types.

| Part | Status |
|---|---|
| An imported executed PDF is never re-rendered or re-sealed | proven in principle — `TcLibPdfSealerTest::test_it_leaves_the_input_bytes_untouched`, and `docs/stage0/sealing.md` records that an already-signed input cannot be counter-sealed, so imports must stay unsealed |
| Captured Firma fixtures are shape-checked and contain no real identifiers | proven — `tests/Feature/Compatibility/FirmaFixtureShapeTest.php` |
| **Import of an executed Firma document, with the vendor hash retained separately from this application's own digest** | **not proven** — no import path exists |
| **Reconciliation, and retaining all four artifact types for a migrated request** | **not proven** |
| **Existing requests remaining on Firma** | **not proven** — a consumer-side property |
| The four pilot workflows of `docs/HANDOFF.md` §15 — platform NDA, practice NDA, order form, data destruction — driven against the self-hosted provider by a synthetic consumer, including recipient-signature timing, countersigning, polling reconciliation, cancellation, download, and retention of all three published artifacts | proven, new in this branch — `tests/EndToEnd/` |

**Owners:** [#42](https://github.com/bherila/e-sign/issues/42) and
[#43](https://github.com/bherila/e-sign/issues/43) (consumer migration),
[#33](https://github.com/bherila/e-sign/issues/33) (the facade),
[#36](https://github.com/bherila/e-sign/issues/36) (end-to-end contract tests with a synthetic
consumer).

### 14. Recovery and maintenance — **partly proven**

> Restore verifies old artifacts; key rotation preserves historical verification; pending
> finalization and webhook failures are visible and replayable.

| Part | Status |
|---|---|
| A restore drill verifies restored documents against their recorded digests, with outbound mail and webhooks suppressed at both the enqueue and the send end | proven — `tests/Feature/Evidence/Retention/BackupRestoreDrillTest.php`, `RestoreDrill` |
| Artifact integrity verification by streaming re-hash | proven — `tests/Feature/Evidence/Retention/ArtifactIntegrityTest.php`, `esign:artifacts:verify` (scheduled) |
| Key rotation preserves historical verification: a key id is recorded on every artifact and the certificate for every key id is retained | proven — `tests/Feature/Evidence/SealKeyManagementTest.php`, `docs/operations/seal-key-management.md` |
| Webhook backlog visible and replayable; a disabled endpoint is a visible state | proven — `WebhookConsoleTest`, `WebhookBacklogProbeTest`, `esign:webhooks:replay` |
| Mail backlog visible and resendable | proven — `MailConsoleCommandTest`, `MailBacklogProbeTest` |
| Pending finalization visible, and started | proven — `finalization_runs`, the `finalization_backlog` probe, and `esign:finalization:resume`, which re-dispatches a finalization whose queued job never ran; `FinalizationBacklogProbeTest`, `FinalizationResumeCommandTest` |
| **A restore drill exercised against a real backup on real infrastructure** | **not proven** — the runbook exists (`docs/operations/backups.md`); the drill has not been run |
| **Key rotation exercised end to end on a deployment** | **not proven** — the command and the invariants are tested; no deployment has rotated |

### 15. Accessibility and legal readiness — **partly proven**

> Keyboard/mobile signer flow, accessible signature alternative, approved consent/copy/retention
> process, and honest assurance documentation.

| Part | Status |
|---|---|
| **Honest assurance documentation** | **proven, and it is what this branch adds** — [`docs/assurance.md`](../assurance.md), linked from `README.md` and `docs/HANDOFF.md`'s status line |
| An accessible signature alternative: typing is the default path, not a fallback | proven by construction and by the rendered page — the typed tab is the one that opens, and `docs/signing/guest-access.md` records the reasoning |
| The consent notice is a reviewed repository file, versioned, snapshotted on the envelope, and checked at acceptance — including, new in this branch, that the text on disk is the version the envelope names | proven — `GuestSigningAcceptanceTest`, `EnvelopeAcceptanceTest` |
| A default retention policy that deletes nothing until an operator configures one | proven — `RetentionSweepTest` (unset, `''`, and `'0'` each leave an executed envelope alone) |
| **Keyboard and mobile signer flow verified against a real browser** | **not proven.** The Jest suites cover the reducers and the field table; there is no browser-driven or axe-style check, and no viewport test. |
| **Counsel's approval of the consent text, signer authority, access/copy, and retention** | **not proven, and not a software gate.** `docs/assurance.md` §8 lists what needs approving. |

---

## Gaps this document is the record of

Ordered by what a first production use would most want closed.

| Gap | Gate | Owner |
|---|---|---|
| No end-to-end smoke test on either deployment *profile* (the functional flow itself is now covered by `tests/EndToEnd/`) | 11 | [#38](https://github.com/bherila/e-sign/issues/38) |
| Object-stream bodies are parsed in a nested parser instance that does not charge the preflight budget (residual of U-1; covered by the time and memory backstops) | 3 | new — [`review-2026-09.md`](review-2026-09.md) U-1 |
| The hazard scan fails open past depth 32, and on a null-resolving xref entry | 3 | new — [`review-2026-09.md`](review-2026-09.md) U-3, U-4 |
| **No static analysis beyond formatting and `tsc`** (the `composer audit` / `pnpm audit` half of [`review-2026-09.md`](review-2026-09.md) X-13 landed as the `audit` job) | all | new — [`review-2026-09.md`](review-2026-09.md) X-13 |
| `publish()` does not re-check object presence before committing | 6 | new — [`review-2026-09.md`](review-2026-09.md) B-2 |
| `updateDraft()` races `send()` without a lock | 5 | [#33](https://github.com/bherila/e-sign/issues/33) |
| No browser-driven accessibility or mobile check | 15 | new |
| Egress is blocked at the process level, not the network level | 12 | new — see the caveat under gate 12 |
| Real browser SSO callback; entitlement re-check window | 9 | [#44](https://github.com/bherila/e-sign/issues/44) |
| Restore drill and key rotation never exercised on a deployment | 14 | operations |
| Counsel review of consent, authority, access/copy, retention | 15 | not a software gate |

---

## Related

- [`docs/assurance.md`](../assurance.md) — what the product claims and does not.
- [`docs/security/review-2026-09.md`](review-2026-09.md) — the adversarial review behind the new
  entries above.
- [`docs/HANDOFF.md`](../HANDOFF.md) section 14 — the gate table this document answers.
- [`tests/EndToEnd/README.md`](../../tests/EndToEnd/README.md) — the synthetic-consumer suite: what it
  proves, and the six things it explicitly does not.
- [`TESTING.AGENTS.md`](../../TESTING.AGENTS.md) — the local validation contract.
