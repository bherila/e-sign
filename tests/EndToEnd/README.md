# End-to-end contract tests with a synthetic consumer

The suite for [issue #36](https://github.com/bherila/e-sign/issues/36). A test double of the
**consumer application** drives this one from the outside: it creates signing requests through
the Firma-compatible facade, receives and verifies webhooks on its own endpoint, follows the
invitations recipients are mailed, signs on the guest pages, runs the queued finalization job,
downloads and validates the sealed PDF, and pulls the evidence bundle — for all four workflow
shapes, with every external destination blocked.

```bash
php artisan test --testsuite=EndToEnd     # this suite alone
composer test                             # the whole backend gate, this suite included
```

The suite is a testsuite in `phpunit.xml` rather than a CI job of its own, so it runs behind
the existing `test` job on both PHP 8.4 and 8.5, and behind the `database` job against MySQL
8.4, MariaDB 11.4 and MariaDB 10.6.

---

## What is real, and what is faked

Everything between the consumer's HTTP call and the bytes on disk is the product:

| Real | Where |
|---|---|
| The Firma-compatible facade, over the real HTTP kernel | `routes/compat-firma.php`, `$this->postJson(...)` with the raw key in `Authorization` |
| Service credentials, scopes, and tenant scoping | `ServiceCredentialIssuer`, `require-scope:compat:firma-v1` and the resource scopes |
| The signing state machine | `EnvelopeStateMachine` — every transition in this suite goes through it |
| Invitations and signing URLs | the real `InvitationIssuer` / `InvitationSigningUrlMinter`, reached through the real mail scheduler |
| The guest signing surface | landing `GET`, start `POST`, values `POST`, accept `POST`, with the encrypted session cookie carried forward as a browser carries it |
| The transactional outbox and webhook delivery | `OutboxWriter`, `WebhookDispatcher`, `DeliverWebhook`, `WebhookSigner`, `DestinationPolicy` |
| The mail outbox | `MailOutbox`, `ScheduleEnvelopeMail`, `SendOutboundMail` |
| Finalization, sealing, validation, publication | `EnvelopeFinalizer`, `TcLibPdfSealer`, `ArtifactValidator`, `DiskArtifactStore` |
| The native evidence export | `GET /api/v1/envelopes/{id}/evidence-bundle` |

Four things are faked, all of them the boundary of the process:

| Faked | How | Why |
|---|---|---|
| HTTP egress | `Http::preventStrayRequests()` plus one fake matching the consumer's own endpoint | Nothing may leave the process. See "Independence" below. |
| The timestamp authority | `RefusingTimestampAuthority`, bound for the whole suite | `HttpTimestampAuthority` reaches the network through Guzzle directly, so the stray-request fence cannot see it. Refusing makes a B-T request a legible failure rather than a packet. |
| The storage backend | `Storage::fake('documents')`, and `InMemoryObjectStore` for the second run | No container runtime here, so no Garage. See "Storage portability". |
| The mail transport | the `array` mailer, from `phpunit.xml` | See "Mail". |

and two jobs are *held on the queue* rather than faked away: `DeliverWebhook` and
`FinalizeEnvelope`, the two a worker owns. The test plays that worker — delivering the attempts
that are due, in an order it chooses, and running the finalization the application queued.
Neither may run inline: on the synchronous queue a delivery would happen inside the request
that caused it and the finalization would seal the document inside the guest request that
completed the signing, which is not where a worker runs. Every other job — outbox fan-out, mail
scheduling, sending — does run on the synchronous queue, which honours `afterCommit()`.

---

## What it proves

### The four workflows

Each mirrors one of the recorded fixtures under `tests/Fixtures/firma/firma-compat-v1/`.

| File | Shape | Created by |
|---|---|---|
| `PlatformNdaWorkflowTest` | two recipients, sequential; the second countersigns | a published template, plus a `PATCH` of a prefill by variable name |
| `PracticeNdaWorkflowTest` | buyer then seller | `create-and-send` with a base64 PDF and **percent** coordinates |
| `OrderFormCancellationTest` | sent, half-signed, then withdrawn | `create-and-send` |
| `DataDestructionWorkflowTest` | a single recipient | `create-and-send` |

Every completing workflow asserts the same spine: `signing_request.sent` arrives and verifies;
the active recipient's invitation is minted by the real issuer and read out of the queued
message's context, exactly as a mail client reads it; the guest flow is driven over HTTP as
that recipient; `signing_request.recipient.signed` arrives and the next recipient is invited;
after the last signature the envelope is in `finalizing` and **no completion has been
announced**; the finalization job the real trigger queued is run; `signing_request.completed`
arrives only once a
validated artifact is published, asserted at the moment of receipt; `/download` reports
`finished` and not partial; the PDF fetched through the signed URL hashes to the digest
publication recorded; `ArtifactValidator` reports PAdES **B-B** with no failures; and the
evidence bundle is a ZIP whose manifest digests match its own files.

The cancellation path additionally asserts that `signing_request.cancelled` carries `sent` and
`cancelled` both true, that `/download` serves the **reviewed revision** with `is_partial` true
and its own `X-Document-Sha256`, that the signature already given still stands, that nothing
was ever sealed, and that the pending recipient's link now answers 403 on both the landing GET
and the start POST.

### Independence — `IndependenceTest`

`Http::preventStrayRequests()` is on for the whole run and the only registered fake matches the
consumer's endpoint. That is stronger than blocking two vendors: **every** destination is
blocked and only one is opened. The test asserts, by data provider, that Firma hosts, DocuSign
hosts, a CDN and an analytics beacon all raise; then runs a complete workflow under the same
fence and asserts the only URL the application put on the wire during it was the consumer's own
callback.

It also asserts the seal reached B-B with no signature timestamp and that the bound timestamp
authority refuses to declare itself usable — a level that cannot be met is an error, never a
silent downgrade.

### Mail — `IndependenceTest`

The whole suite runs on the `array` mailer. The invitation reaches `sent_to_provider` (not
`delivered`: signing never depends on falsely declaring an address reachable), carries the
signing URL, and that URL is the one the harness uses to drive the guest flow — so "the mail
works" and "the link works" are the same assertion.

### Storage portability — `StoragePortabilityTest`

The second backend is `InMemoryObjectStore`, a flat key space with **no directories**, which is
the shape an S3-compatible bucket has and the shape that breaks code quietly depending on a
real filesystem. Three assertions:

1. the three published artifacts, re-uploaded through the real `ArtifactStore::putVerified()`,
   read back with **identical digests and identical bytes** on both adapters, through `get()`
   and through `readStream()`, and the prefix listing the staging pruner relies on still finds
   them;
2. a complete workflow — intake, sealing, publication, download, validation — runs on the object
   store, with no bucket or key appearing in the download URL;
3. the retained original is byte-identical across the two backends.

**What is deliberately not asserted:** that two independent seals of the same agreement produce
the same bytes. `tc-lib-pdf` writes a creation time and a file identifier into every document,
so two runs differ for reasons that have nothing to do with storage. The identical-digest claim
is about one artifact read through two adapters, which is the claim that is actually about the
backend.

### Worker interruption — `WorkerInterruptionTest`

The finalization job is interrupted inside the publishing transaction, after the three objects
have been uploaded and read back. The artifact rows roll back, the objects stay, the envelope
lands in `finalization_failed`, and the consumer receives `esign.envelope.finalization.failed`
— a name of ours, namespaced, because the profile has no such event and inventing a
`signing_request.*` one would put a string on the wire nobody handles. After
`retryFinalization()` and a second run: nothing is sealed or stored again, the same three
objects are published at generation 2, and there is **exactly one** logical
`signing_request.completed`, processed exactly once. A second one would be worse than none.

A companion test shares one log between the artifact store and the event sink and asserts the
completion event was recorded *after* every read-back — an ordering no end-state assertion can
see.

### Webhook reliability — `WebhookReliabilityTest`

A receiver that answers 500 once gets a retry with a **fresh timestamp**, a new attempt id, and
the **same event id** — and the receiver, which enforces a five-minute replay window, would
reject the retry if the timestamp were not fresh. A lost response is retried and the receiver
deduplicates it. A request that never arrives is retried until it does. Three events handed
over newest-first all verify, and the receiver's inbox refuses to regress to the older two while
still acknowledging them. A replay is a new attempt of the same event with the same bytes, and
is ignored. During a rotation overlap both secrets verify: a receiver still on the old one and a
receiver moved to the new one each accept the same delivery, and the upstream-shaped
`X-Firma-Signature-Old` header verifies with the old secret alone. A body the receiver cannot
verify is a **400**, never a 500, and is not retried.

**Replay protection inside the window is the receiver's**, and `docs/delivery/webhooks.md` says
so. Every idempotence assertion here is therefore an assertion about
`ConsumerWebhookReceiver` — the consumer's code in this test — and not a claim that the sender
deduplicates. The receiver's HMAC verification is written from the documented scheme and imports
nothing from `App\Domain\Delivery\Webhooks\WebhookSigner`, so a change to our signer that
stopped matching the published contract breaks this suite instead of moving with it.

---

## What it does **not** prove

Stating this is the point of the file.

- **No browser.** Every request goes through Laravel's HTTP kernel. There is no JavaScript, no
  PDF.js, no canvas, no viewport, and no accessibility check. Keyboard and mobile signer flows
  are still unproven (release gate 15).
- **No real network.** "Firma/DocuSign hosts blocked" is enforced at the *process* level, not
  the network level. A process-level fence cannot see a socket opened outside the HTTP client;
  the timestamp authority is the one place in this application that does that, which is why it
  is bound to a refusing double rather than merely fenced. A CI job with egress blocked at the
  network layer would be a strictly stronger statement and does not exist.
- **No real TSA, and therefore no B-T.** Everything here seals at PAdES B-B. A token this
  process minted for itself would not be an independent witness, and `docs/assurance.md` is
  explicit that B-T rests on a third party. B-T is covered in the sealing suite against a real
  authority, which skips honestly when there is no egress.
- **No real S3 and no Garage.** `InMemoryObjectStore` speaks Flysystem, not S3-over-HTTP, so it
  says nothing about signatures, multipart uploads, or eventual consistency. What it establishes
  is that nothing in the publication path branches on the driver or needs the local one.
- **No profile conformance.** `ArtifactValidator` is an in-process self-check that shares the
  signing library with the sealer. The authoritative validation is the `validation` CI job
  running pyHanko against the committed artifacts; ETSI EN 319 142-1 conformance remains
  unproven (release gate 2, issue #7).
- **No deployment.** This is the application in a test process, not a container or a cPanel
  account. The Docker and cPanel smoke tests release gate 11 asks for are issue
  [#38](https://github.com/bherila/e-sign/issues/38).
- **Not a migration test.** Nothing here imports an executed Firma document or reconciles a
  request that stayed on Firma. Release gate 13 is still owned by
  [#42](https://github.com/bherila/e-sign/issues/42) and
  [#43](https://github.com/bherila/e-sign/issues/43).

### One gap this suite found, and how it was closed

**Nothing in `app/` used to dispatch `FinalizeEnvelope`.** An envelope that reached
`finalizing` sat there until something started the work, and in the first version of this suite
the harness played that part — a real hole in the product rather than a property of the test,
recorded here rather than hidden behind a helper that looked like production wiring
([#94](https://github.com/bherila/e-sign/issues/94)).

`App\Domain\Evidence\Finalization\FinalizationTrigger` now dispatches it, after commit, on
the acceptance that lands the envelope in `finalizing`, with `esign:finalization:resume` behind
it for a job that is lost and a `finalization_backlog` readiness probe over the same set. The
harness no longer dispatches anything: `FinalizeEnvelope` is faked on the queue beside
`DeliverWebhook`, and `SyntheticConsumer::runFinalizationWorker()` **asserts the application
queued it** and then runs it, which is what a worker does. Holding it on the queue is still
necessary — on the synchronous queue the trigger would seal the document inside the facade or
guest request that caused it, which is not where a worker runs — but the dispatch under test is
now the product's.

The one dispatch the harness still makes is the operator's: `retryFinalization()` re-queues a
`finalization_failed` envelope, because that state is deliberately outside the resume sweep and
the transition publishes no event.

---

## The harness

`tests/Support/SyntheticConsumer/`:

| Class | What it is |
|---|---|
| `SyntheticConsumer` | The consumer application: credential, endpoint, facade calls, guest-flow driver, worker (runs the queued webhook and finalization jobs; dispatches neither), egress fence |
| `ConsumerWebhookReceiver` | Its inbox, on a route registered inside the test. Verifies, deduplicates, refuses to regress, and can be told to fail |
| `FirmaSignatureVerifier` | The `X-Firma-Signature` scheme, implemented from the documentation and nothing else |
| `ReceivedEvent` | One POST as the receiver saw it — an attempt, not an event |
| `InMemoryObjectStore` | A flat, in-memory Flysystem adapter shaped like an S3 bucket |
| `RefusingTimestampAuthority` | A TSA that is not configured and says so loudly |
| `ObservingEnvelopeEventSink` | The real sink, with a shared ordering log and an optional interruption |

All data is synthetic (`AGENTS.md`): `.test` and `.example.test` addresses that no resolver
answers, RFC 5737 TEST-NET-3 for the callback host, the committed PDF fixtures, and the
generated fixture seal key.

---

## Release gates this suite bears on

`docs/security/release-gates.md` is the record; the rows this suite moves are **7 (webhooks)**,
**8 (isolation)**, **11 (runtime portability)** and **12 (independence)**, with supporting
evidence for **6 (artifact durability)** and **13 (migration)**. The "nothing dispatches
`FinalizeEnvelope`" entry it opened against gates 6 and 11 is closed and no longer listed
there. Read that document for the
current status of each — including the parts that are still not proven.
