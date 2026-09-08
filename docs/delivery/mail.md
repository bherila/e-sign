# Outbound mail

Every transactional message BWH eSign sends — invitation, reminder, decline, cancellation,
completion, guest sign-in code, operator failure notice — goes through the outbox in
`app/Domain/Delivery/Mail`. Nothing calls `Mail::send()` directly.

The reason is a single question that has to be answerable months later: *was this person
told, and what happened to the message?* A `Mail::send()` in a controller answers "we tried".
A row in `outbound_mails` answers the question.

## What "delivered" means, and what it does not

The states are deliberately distinct. Collapsing them would be the whole failure mode.

| State | What is actually known | Who can write it |
|---|---|---|
| `queued` | The row exists. Nothing has been handed to a transport. | The application, at enqueue |
| `sent_to_provider` | A transport accepted the bytes and returned a Message-ID. A statement about a TCP conversation. | The application, after a send |
| `accepted` | The provider has confirmed it took ownership (Brevo `request`, SES `Send`). Still not a mailbox. | Provider feedback |
| `delivered` | A receiving mail server accepted final delivery. | Provider feedback only |
| `bounced` | It did not arrive — rejected by the receiving server, or failed inside the provider after it took the message. | Provider feedback |
| `complained` | It arrived and the recipient reported it as spam. | Provider feedback |
| `failed` | The application gave up: every attempt raised a transport error. | The application, at final failure |

Three consequences worth stating plainly:

- **`queued` is not `sent`.** A queued message with no worker running has gone nowhere. That
  is what the `mail_backlog` readiness probe exists to notice.
- **`sent_to_provider` is not `delivered`.** It means an SMTP server or an HTTP API said
  "got it". It says nothing about the recipient's mailbox, their spam folder, or their
  employer's gateway.
- **`delivered` is not "read", and it is not consent.** It means a receiving mail server
  accepted the message. No template contains a tracking pixel and engagement events from
  providers are discarded unstored, so this product does not know and does not record
  whether anyone opened anything.

**Signing never depends on any of this.** An envelope is not "sent" because mail was
delivered, and it is not blocked because mail bounced. A bounce is information for the
sender, not a state transition in the signing machine. This follows the release invariant in
`docs/HANDOFF.md` section 11: external email transport is at-least-once, and signing success
does not rest on falsely declaring email delivered.

## A logged message is not a sent message

`ProductionMailerGuard` refuses to enqueue anything when `APP_ENV=production` and
`MAIL_MAILER` is not one of `hybrid`, `brevo`, `smtp`, or `ses`.

The allowlist is a constant in the class, not configuration. If it were configurable, the
mistake it exists to prevent — mail settings that say `log` — would be reachable by editing
mail settings.

`log`, `array`, and the stock `failover` mailer (whose second leg is `log`) are all refused.
Outside production nothing is refused: `log` is how development works and `array` is how the
test suite works.

The check runs twice, at enqueue and again in `OutboundMailSender` before each send.
Configuration can change between the two, and a worker that has been running since before
the change is exactly how a `log` mailer would otherwise get to claim a send.

## How a message is sent

1. Something calls `MailOutbox::enqueue($kind, $recipient, $context, $workspace, $related)`.
2. The guard runs. The Mailable is built — which validates the context and yields the
   subject that is stored on the row, so the stored subject is the one that goes out.
3. The row and a `queued` event are written in one transaction, and `SendOutboundMail` is
   dispatched *after commit*. This is the transactional-outbox pattern: a worker can never
   pick up a message whose row is not visible yet, and a rolled-back caller cannot leave a
   job pointing at a row that does not exist.
4. The job renders the Mailable from `outbound_mails.context` and sends it through
   `mail.default`. It is `ShouldBeUnique` on the message ULID, so a retried enqueue or a
   worker restart mid-dispatch cannot put two copies of the same invitation, with the same
   link, in one mailbox.
5. On success the row records `sent_to_provider`, the mailer name, and the normalized
   provider Message-ID from the Symfony `SentMessage`.
6. On a transport error the attempt count and a redacted error are recorded and the exception
   is re-thrown so the queue retries. The state stays `queued`, because nothing has been
   handed over.
7. When the queue gives up, the job's `failed()` hook writes `failed`.

Retries come from `ESIGN_MAIL_TRIES` and `ESIGN_MAIL_BACKOFF`. Send jobs go on their own
queue (`ESIGN_MAIL_QUEUE`, default `mail`) so mail never waits behind sealing work — **run a
worker on it**, or nothing is sent:

```bash
php artisan queue:work --queue=mail
```

## The mail context

Mailables render from one plain data object, `MailContext`, and nothing else. The outbox is
built before envelopes exist and deliberately does not depend on them: whatever produces a
message flattens what it wants said into this object, which is persisted verbatim in
`outbound_mails.context`.

It has exactly one URL field, `actionUrl`, and that is a product rule rather than a
convenience. A transactional message carries at most one opaque credential, so a forwarded
or scanner-fetched mail exposes one thing rather than a set. The URL must arrive already
authorized and already absolute — the outbox mints nothing and signs nothing — and it is
rejected unless it is absolute `http`/`https`, free of credentials in the authority, free of
a fragment (a token there is never sent to the server, so it can never be checked or
revoked), and carrying at most one query parameter.

`context` is view data. It is never a place for a credential on its own, an API key, or
document bytes.

## Templates

Markdown mail under `resources/views/mail`, so Laravel generates the plain-text alternative
from the same source and the two cannot drift.

| Kind | Goes to | Carries a link |
|---|---|---|
| `invitation` | the recipient asked to sign | the signing URL |
| `reminder` | the recipient, again | the same signing URL |
| `declined` | the sender | no |
| `cancelled` | every recipient who was invited, including those who already signed | no |
| `expired` | every recipient who was invited, and the sender | no |
| `completed` | every party | a download URL, optional |
| `otp` | a guest starting a signing session | **never** — see below |
| `admin_failure` | the workspace's owners | no |

`expired` is separate from `cancelled` rather than reusing it. An expiry is the clock
arriving and a cancellation is a person deciding; telling somebody their agreement "was
cancelled" when nobody cancelled it is the kind of wording `AGENTS.md` rules out.

Who receives each one, and when, is `docs/delivery/envelope-events.md`. The short version:
mail is scheduled after the transition commits, never inside it, and a recipient who has
never been invited is never told about an agreement they were not shown.

`otp` is the exception to that paragraph: nothing in the envelope lifecycle sends one. It is
issued by `App\Domain\Signing\Sessions\OtpChallenges` when a guest asks to start a signing
session on an envelope that requires a mailed code.

No images, no remote stylesheets, no third-party assets, no tracking pixels. A message that
fetches nothing renders identically in a client with remote content blocked, and cannot
report when it was read.

`otp` is the only template that carries a credential in its *text*, and the only one that
must never carry a link at all: a code and a one-click URL in the same message would let one
forwarded mail satisfy both halves of the check the code exists to separate. It follows that
the code is stored — the outbox renders from a persisted context, which is what makes delivery
survive a crash — so a live code sits in `outbound_mails.context` for the ten minutes it is
worth anything. `docs/signing/guest-access.md` states that limit rather than implying the code
is a secret from the operator.

The completion mail does **not** attach the executed PDF. That is a decision, not an
unfinished feature: mail is not a place to put an executed instrument that has to stay
retrievable, byte-identical, and access-controlled for years, and a copy loose in a mailbox
is a copy nobody can account for. The link streams through the application, where the
request is authorized every time (`docs/BLOB_STORAGE.md`).

Branding comes from `APP_NAME`. This is self-hosted software and the installation is not
called the same thing everywhere.

### Markdown, not just HTML, has to be escaped

Blade escapes HTML. It does not escape Markdown, and these are Markdown mailables, so
Blade's HTML-escaped output is handed to CommonMark afterwards: `[click here](https://evil.test)`
in any interpolated field becomes a live link in the delivered message.

The fields that reach these templates are not trusted. A decline reason is typed by an
external signer who holds nothing but a signing link, and the resulting notice goes to the
*sender* — who has every reason to trust a link in a message from their own agreement
service. Recipient names, sender names, and agreement titles are no more trustworthy.

So the templates never see a `MailContext`. They see `App\Mail\MailCopy`, which neutralizes
`\`, `[`, `]`, `` ` ``, `*`, `_`, `~`, and `|` in every text field on the way in. Two
consequences worth knowing:

- `$actionUrl` is deliberately **not** escaped. It is the one URL the message may carry, its
  shape is already validated, and it is only used in a Blade attribute
  (`<x-mail::button :url="…">`) where HTML escaping is the right protection.
- The subject is built from the raw context, not the copy. A subject is a header, never
  Markdown, and backslashes added for CommonMark's benefit would be read literally by every
  mail client.

`<`, `>`, `&`, `"`, and `'` need no rule here: Blade turns them into entities before
CommonMark sees them, which is what kills raw HTML and `<autolink>` syntax. Bare URLs are not
links either, because Laravel's mail Markdown environment loads only the CommonMark core and
table extensions with no autolink extension — asserted in `MailableRenderingTest` rather than
assumed, so a framework change that adds autolinking fails a test instead of shipping.

## Reminders

`esign:signing:remind` runs daily from `routes/console.php`. Its conditions are facts about a
row — active, invited more than `esign.signing.reminder_after_hours` ago, not reminded within
`esign.signing.reminder_interval_hours` — so an extra run sends nothing extra. Details and the
`last_reminded_at` claim are in `docs/delivery/envelope-events.md`.

## Provider feedback

`sent_to_provider` only becomes `delivered`, `bounced`, or `complained` when a provider says
so. Both endpoints are in `routes/mail-webhooks.php`.

> **Not yet registered.** `bootstrap/app.php` does not yet include
> `routes/mail-webhooks.php`; that file is owned by another change in flight. Add
> `Route::group([], base_path('routes/mail-webhooks.php'));` to its `then:` closure to turn
> these endpoints on. Until then the outbox works without them and messages simply stay at
> `sent_to_provider`, which remains an honest statement of what is known.

Feedback is matched to a row **by Message-ID only**. Matching by address would be the obvious
alternative and is the wrong one: the same recipient can hold several open invitations, and
marking the wrong one bounced would tell a sender their counterparty is unreachable when the
message that failed was a different agreement's reminder.

Message-IDs are normalized — angle brackets stripped, lowercased — because Symfony, Brevo,
and SES each spell the same identifier differently.

Feedback about a Message-ID with no row is recorded as an **orphan** rather than dropped.
That happens routinely: a restored backup, a shared sending domain, a webhook pointed at the
wrong environment. A silent 200 would make a misconfigured provider indistinguishable from a
quiet one.

Duplicate and out-of-order webhooks are expected. Providers retry, so a `delivered` can
arrive after the `bounce` that superseded it. Ordering is decided by rank
(`MailState::supersedes()`), not by arrival time, and a negative outcome always wins because
that is the state an operator has to see.

### Brevo — `POST /webhooks/mail/brevo`

**Brevo signs nothing.** No HMAC, no computed header, no published source-IP range. The
entire authentication story is a shared token, checked with `hash_equals` in the Form
Request:

```bash
ESIGN_MAIL_BREVO_WEBHOOK_TOKEN=$(php -r 'echo bin2hex(random_bytes(32));')
```

The token is accepted in an `X-Esign-Mail-Token` header or a `?token=` query parameter. The
header is preferable — it stays out of logs on both sides — but Brevo's webhook
configuration is a URL field, so the query parameter is the mechanism that actually works.

**An unset token disables the endpoint rather than opening it.** With no token there is
nothing to check, so nothing is accepted.

| Brevo event | State |
|---|---|
| `request` | `accepted` |
| `delivered` | `delivered` |
| `hardBounce`, `softBounce`, `blocked`, `invalid_email`, `error` | `bounced` |
| `spam` | `complained` |
| `deferred` | recorded, no change (still in flight) |
| `unsubscribed` | recorded, no change |
| anything unrecognized | recorded, no change |
| `opened`, `click`, and other engagement events | **discarded, not stored** |

`unsubscribed` is recorded and moves nothing. It is not a spam complaint, it is not evidence
about delivery, and there is no suppression state to put it in — and `complained` would be
both the wrong claim and the top rank, so nothing could ever correct it afterwards.

Engagement events are dropped entirely. The templates carry no tracking pixel by design, and
storing an `opened` row would reintroduce exactly that data through the provider's side door.

A batch always answers 200. Brevo redelivers the whole batch on any non-2xx, so failing it
for one unrecognized event would put the provider into a retry loop over an event the
application has already chosen to ignore. Entries with no event name are dropped before
validation; a body with nothing event-shaped in it at all is a 422.

### SES — `POST /webhooks/mail/ses`, and why it refuses everything

**This endpoint currently rejects every message, on purpose.** It is wired, reviewed, and
tested, and it accepts nothing.

SNS signs its messages, and verifying that signature is the only thing separating this route
from an unauthenticated endpoint that lets anyone mark any message bounced. Doing it properly
means fetching the certificate named by `SigningCertURL`, checking that the URL is an
AWS-controlled `sns.<region>.amazonaws.com` host over HTTPS, rebuilding the canonical
string-to-sign per message type, verifying with the certificate's public key, and caching
certificates so a webhook storm is not also a certificate-fetch storm. That is what
`aws/aws-sns-message-validator` does, and this project does not have it: `aws-sdk-php` is
present only transitively through `league/flysystem-aws-s3-v3` and does not include the
validator.

So rather than hand-roll it — or, far worse, accept unsigned input until someone gets round
to it — the endpoint fails closed. `RejectingSnsMessageVerifier` refuses every message and
the response is **503**, not 403: the caller has done nothing wrong and cannot fix it, and
SNS treats 503 as retryable, so a deployment that later turns verification on does not lose
the feedback that arrived in the meantime.

Everything behind the verifier is implemented and tested: the topic-ARN check
(`ESIGN_MAIL_SES_TOPIC_ARN`, unset disables the endpoint), subscription confirmation, and the
notification mapping.

Subscription confirmation is the one outbound request this endpoint makes, to a URL that
arrived in a request body, so it is guarded twice. The host is pinned to
`sns.<region>.amazonaws.com` over HTTPS, which costs no DNS and rejects the obvious attempts
(`sns.us-east-1.amazonaws.com.attacker.test`, a literal address, the metadata endpoint). Then
the shared `DestinationPolicy` — the same one the webhook outbox and the timestamp authority
use — refuses a host that *resolves* to a loopback, private, link-local, or reserved address
and pins the connection to the addresses it checked. Redirects are never followed, because
only the first hop was validated.

| SES `notificationType` / `eventType` | State |
|---|---|
| `Send` | `accepted` |
| `Delivery` | `delivered` |
| `Bounce`, `Reject`, `Rendering Failure` | `bounced` |
| `Complaint` | `complained` |
| `DeliveryDelay` | recorded, no change |

An event is stamped with its own time (`bounce.timestamp`, `complaint.timestamp`, …), not
`mail.timestamp`, which is when the message was *sent* and is identical on every notification
about it. That value becomes `state_changed_at`, so the send time would stamp a bounce
backwards to send and break both the operator timeline and the 24-hour windows the backlog
probe and `esign:mail:backlog` read.

**To turn SES feedback on:** add `aws/aws-sns-message-validator`, implement
`SnsMessageVerifier` over `Aws\Sns\MessageValidator::validate()`, and bind it in
`DeliveryServiceProvider` in place of `RejectingSnsMessageVerifier`. Nothing else changes.

## Redaction

`outbound_mails.last_error` and `outbound_mail_events.payload` go through
`MailErrorRedactor` first. Every substitution exists because a real transport puts the thing
there: an SMTP rejection quotes the envelope recipient back at you, a Brevo API error echoes
the request URLs and all, and a Symfony exception can carry the DSN it was built from, which
contains the API key.

Addresses become `[address]`, whole URLs become `[url]` (so a signing token disappears with
the link that carried it), labelled secrets become `key=[redacted]`, bare high-entropy runs
become `[token]`, and payload keys whose names look like addresses, tokens, signatures, or
certificate URLs are replaced wholesale. Errors are truncated to 500 characters: this is a
diagnostic column, not an archive.

Credential scrubbing itself is delegated to the webhook outbox's `TextRedactor` rather than
reimplemented, so there is one set of rules for JWTs, `Bearer …`, labelled credentials, and
bare opaque runs. Two redactors solving one problem is how you get two different sets of
holes. It also means a 26-character ULID survives on purpose: those are the identifiers an
operator correlates on.

Raw provider bodies are not retained. The redacted copy is enough to explain a state change,
and this table is read by operators far more often than by an incident.

This is a redactor, not a security boundary. It reduces the blast radius of an error message;
it does not license putting a secret in one.

## Operating it

```bash
# What is stuck, what was given up on, and why. Oldest first: the default listing answers
# "what has been waiting longest".
php artisan esign:mail:backlog

# Every state, most recent first: "what just happened".
php artisan esign:mail:backlog --all --limit=50

# Send a message again, as a new message linked to the original.
php artisan esign:mail:resend 01JQZX9K7M4N2P5R8T3V6W1Y0B
```

`esign:mail:resend` never rewinds the original row. That row records that an attempt was
made and how it ended, and an operator resending a bounced invitation should not thereby
erase the bounce. The copy carries `resent_from_id`, so the pair reads as a sequence. The
context — including its action URL — is replayed verbatim; whether that credential is still
valid is decided by its own expiry, not silently rewritten here.

The `mail_backlog` readiness probe reports the same two numbers to a monitor: the age of the
oldest `queued` message and the count that reached `failed` in the last 24 hours. Thresholds
are `ESIGN_MAIL_BACKLOG_WARN_SECONDS`, `ESIGN_MAIL_BACKLOG_FAIL_SECONDS`,
`ESIGN_MAIL_FAILED_WARN_COUNT`, and `ESIGN_MAIL_FAILED_FAIL_COUNT`. It counts `queued` only:
a message at `sent_to_provider` is out of the application's hands, and counting it would make
a working deployment look broken every time a provider was slow with feedback.

It is separate from the `mail` probe on purpose. That one checks that a delivering transport
is configured; this one catches the case where configuration is perfect and nothing is being
sent because no worker is running on the mail queue.

## Mail-domain setup

Deliverability is a DNS exercise, and none of it is in this repository:

- **SPF** — authorize the provider's sending hosts for the envelope domain. One SPF record
  per domain; add the provider's `include:` to the existing record rather than publishing a
  second one.
- **DKIM** — publish the provider's signing keys and confirm the provider reports them
  verified. Unsigned mail from a domain with a DMARC policy will be rejected.
- **DMARC** — publish a policy and a reporting address. Start at `p=none` and read the
  aggregate reports before tightening.
- **Envelope and header From** — `MAIL_FROM_ADDRESS` must be on a domain covered by the SPF
  and DKIM records above. A `From` on a domain the provider is not authorized for is the most
  common cause of "all our invitations go to spam".
- **Return-Path / bounce domain** — if the provider offers a custom bounce subdomain,
  configure it; feedback is more reliable when the return path is on your own domain.

Then verify with a real send to a real mailbox: check `esign:mail:backlog` shows the message
leaving, check the webhook produced a `delivered` event, and check the link in the received
message opens the signing page. A signing page is safe to fetch — `GET` never applies a
signature or consumes a one-shot token — so a mail scanner's preview cannot sign an
agreement.

## Related

- `docs/HANDOFF.md` section 11 — webhook and email reliability requirements.
- `docs/ARCHITECTURE.md` — the Delivery module's boundaries.
- `docs/operations/health.md` — the readiness probes, including `mail` and `mail_backlog`.
