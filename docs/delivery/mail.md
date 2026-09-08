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
survive a crash — so it sits in `outbound_mails.context` between enqueue and send.

`markSentToProvider()` then drops it from the row. That bounds the exposure to the queue's own
latency: seconds under a worker, up to one cron interval on the shared-hosting profile. It used
to be unbounded, because nothing prunes `outbound_mails` and `RecipientEraser` rewrites only the
two name fields, so every code ever mailed stayed readable in the database and in every backup
([`docs/security/review-2026-09.md`](../security/review-2026-09.md), finding D-4). A retry before
a successful send still has the code, because a retry still has to render; `resend()` mints a
fresh row from a fresh context. `docs/signing/guest-access.md` states the remaining limit rather
than implying the code is a secret from the operator.

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
so. Both endpoints are in `routes/mail-webhooks.php`, registered from `bootstrap/app.php`'s
`then:` closure.

Feedback is matched to a row **by Message-ID only**. Matching by address would be the obvious
alternative and is the wrong one: the same recipient can hold several open invitations, and
marking the wrong one bounced would tell a sender their counterparty is unreachable when the
message that failed was a different agreement's reminder.

Message-IDs are normalized — angle brackets stripped, lowercased — because Symfony, Brevo,
and SES each spell the same identifier differently. SES goes further and reports an
identifier of its own that is not the SMTP `Message-ID` at all; see
[SES's message id is not the SMTP `Message-ID`](#sess-message-id-is-not-the-smtp-message-id).

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

### SES — `POST /webhooks/mail/ses`

SES publishes bounce, complaint, and delivery feedback through SNS, and SNS signs every
message it sends. That signature is the whole authentication story for this endpoint:
without it, the route is an unauthenticated POST that lets anyone mark any message bounced.

**The topic allowlist is the switch.** `ESIGN_MAIL_SES_TOPIC_ARNS` is a comma-separated list
of SNS topic ARNs and it is empty by default. Empty means
`App\Domain\Delivery\Mail\Feedback\RejectingSnsMessageVerifier` is bound and every message
is refused, so an unconfigured deployment fails closed.

That is not a formality. A valid AWS signature proves only that *some* AWS customer signed
the message — anyone can create a topic and sign one — so without an allowlist there is no
answer to "is this our topic?", and accepting on the signature alone would let any AWS
customer move this deployment's mail rows. A topic ARN is not a secret (it is in console URLs
and in CloudTrail), which is exactly why it is a routing decision and not the authentication.

#### What is verified

`AwsSnsMessageVerifier` runs before anything touches a mail row. In order:

1. **A topic is configured**, and the message names one on the list. `SesWebhookRequest`
   checks the same thing first, so neither layer is load-bearing alone; the request's copy is
   what makes a foreign topic a permanent **403** rather than a retryable 503.
2. **`SignatureVersion` is 1 or 2**, and **1 is refused** unless
   `ESIGN_MAIL_SES_ALLOW_SIGNATURE_VERSION_1=true`. Version 1 is SHA-1, and a signature over a
   hash with practical collisions is not evidence. Set the topic's `SignatureVersion` to 2 in
   SNS rather than turning this on.
3. **`Timestamp` is inside the replay window** (`ESIGN_MAIL_SES_REPLAY_WINDOW_SECONDS`,
   default 900), in *both* directions. An SNS signature never expires, so without this a
   captured `Bounce` can be replayed for as long as the certificate lives; a future-dated
   message is refused too, because that is an edited envelope rather than clock skew.
4. **`SigningCertURL` names an AWS SNS signing certificate**: HTTPS, a `.pem` path, and a host
   matching `sns.<region>.amazonaws.com` or `sns.<region>.amazonaws.com.cn`. That pattern
   covers the commercial, GovCloud, and China partitions and nothing else — an S3 bucket on
   `amazonaws.com` and `sns.us-east-1.amazonaws.com.attacker.test` are both out. Judged on the
   spelling, so a hostile URL costs no outbound request at all.
5. **The certificate is fetched through the shared `DestinationPolicy`** — the same one the
   webhook outbox and the timestamp authority use. The name pin above cannot catch a
   legitimate AWS name whose DNS answer points inside the network; the policy refuses a host
   resolving to a loopback, private, link-local, or reserved address and pins the connection to
   the addresses it checked. Redirects are never followed, because only the first hop was
   validated. The answer must be PEM-shaped and under 16 KB.
6. **The signature verifies** against the certificate's public key, over AWS's canonical
   string-to-sign for the message's type.

Step 6 is `aws/aws-php-sns-message-validator` rather than local code, and deliberately so.
The delicate part of SNS verification is not the RSA call, it is the string-to-sign: an
ordered field list that differs between `Notification`, `SubscriptionConfirmation`, and
`UnsubscribeConfirmation`, where each present field is emitted and each absent one skipped.
Getting the order or the presence rule wrong fails on the subset of real messages that carry
a `Subject`, months later, in production — not loudly, in a test. AWS publishes that list;
this uses AWS's copy of it, which is what `AGENTS.md`'s "no bespoke crypto where a library
exists" asks for. The package is Apache-2.0, three files, and adds no transitive dependency:
`aws/aws-sdk-php` was already in the tree through `league/flysystem-aws-s3-v3`, and
`ext-openssl` and `psr/http-message` were already required.

The test suite signs its messages with a keypair and a self-signed certificate generated in
the process, served through a faked HTTP client, and rebuilds the string-to-sign from the AWS
specification rather than calling the library's — so a disagreement about field order fails a
test instead of passing quietly.

**Certificates are cached** by URL for `ESIGN_MAIL_SES_CERT_CACHE_TTL_SECONDS` (default one
hour). Without that, a notification flood is also a certificate-fetch flood against AWS, with
the amplification factor chosen by whoever is sending the flood. Only successful fetches are
cached: caching a failure would turn one bad minute at AWS into an hour of refused feedback.

#### What is refused, and what a refusal looks like

| Situation | Answer | Why that code |
|---|---|---|
| Topic not on the allowlist, or none configured | **403** | Permanent. SNS should stop retrying; the message is somebody else's. |
| Malformed envelope (missing `Message`, `Timestamp`, `Signature`, `SigningCertURL`, …) | **422** | The body is malformed rather than declined, and the two should stay distinguishable. |
| Signature invalid, wrong key, tampered body | **503** | Uninformative on purpose. |
| SHA-1 signature with SHA-1 disabled | **503** | |
| Timestamp outside the replay window | **503** | |
| `SigningCertURL` not an AWS SNS host, or resolving somewhere private | **503** | Nothing is fetched. |
| Certificate unreachable or not a PEM | **503** | Transient; SNS retries a 503. |

503 rather than 403 for everything in the second group: the caller has done nothing wrong and
cannot fix it, SNS treats 503 as retryable, and a deployment that fixes its configuration then
does not lose the feedback that arrived in the meantime. The response body never says which
check failed — an endpoint that mutates mail state should not explain to an unauthenticated
caller how to satisfy it.

**A refusal is never silent.** Every one writes an `outbound_mail_events` row with
`outbound_mail_id` null — the same orphan shape unmatched feedback takes — carrying the reason
token (`topic_not_allowlisted`, `signature_invalid`, `timestamp_outside_replay_window`, …) and
the ARN the message claimed. An operator who has just mistyped `ESIGN_MAIL_SES_TOPIC_ARNS`
otherwise sees nothing at all: SNS reports a 403 on its side, this side reports nothing, and
nobody looks at the two together.

Because this is an unauthenticated public POST, refusals **collapse**: one row per reason per
`ESIGN_MAIL_SES_REFUSAL_WINDOW_SECONDS` (default 300), with the rest of the window logged and
not written. One row per hostile request would be a storage-growth primitive anyone on the
internet could pull. The log line carries the reason and nothing else — no signature, no
certificate URL, no message body.

```bash
php artisan esign:mail:backlog --all   # refusals appear as `refused` events
```

#### Subscription confirmation, and why auto-confirm is off

SNS asks a new endpoint to confirm its subscription by fetching a `SubscribeURL` it supplies
in the request body. `ESIGN_MAIL_SES_AUTO_CONFIRM_SUBSCRIPTIONS` is **off by default**, and
with it off a verified `SubscriptionConfirmation` from an allowlisted topic is answered
**200 `not_confirmed`** and recorded.

Confirming is the act that starts this deployment receiving a topic's traffic, and it is
performed by dereferencing a URL that arrived in a request body. The default is therefore that
a person does it once, in the SNS console, where they can see what they are subscribing to.
200 rather than an error because the message was genuine and correctly addressed: a non-2xx
would make SNS retry something this deployment declined on purpose.

Turn it on for an automated deployment that recreates its own topic subscription. Even then
the URL is guarded twice, exactly as the certificate fetch is: pinned by name to
`sns.<region>.amazonaws.com` over HTTPS, then run through `DestinationPolicy`, which refuses a
host that *resolves* to a loopback, private, link-local, or reserved address and pins the
connection to the addresses it checked. Redirects are never followed. A URL that fails either
guard is a **422** with no detail; AWS being briefly unreachable is a **503**, because losing
a confirmation to a blip would leave the topic unsubscribed with nothing to say why.

#### SES's message id is not the SMTP `Message-ID`

Worth stating separately, because they are two different identifiers for one message and the
row can hold either.

`mail.messageId` in an SES notification is **SES's own** identifier: the value `SendRawEmail`
returns, shaped like `0100019a7f3c0001-…`. The RFC 5322 `Message-ID` header is generated by
Symfony before the message is handed over and looks like `abc@sending-host`. Which one
`outbound_mails.message_id` holds depended on the transport:

| `MAIL_MAILER` | What `SentMessage::getMessageId()` answered | Matched SES feedback |
|---|---|---|
| `smtp` against SES's SMTP endpoint | Symfony parses the id out of the `250 Ok <id>` reply and calls `setMessageId()`, so it is SES's | yes |
| `ses` (the SES API) | Laravel's `SesTransport` adds the SES id as the `X-Message-ID`/`X-SES-Message-ID` headers and never calls `setMessageId()`, so it is the RFC 5322 header | **no** |

So on the API transport every SES notification would have been recorded as an orphan and no
message would ever have left `sent_to_provider`. `OutboundMailSender` now prefers the
`X-SES-Message-ID` header when the transport set one, which fixes it at the one place that
writes the column — a header read, not a check on which mailer is configured, so a transport
that does not set the header is unaffected.

Rows written before that fix are still matchable: `SesFeedbackProcessor` also offers the
`Message-ID` out of `mail.headers` as a fallback candidate. SES includes original headers only
when the notification or event destination is configured to, so it is a fallback rather than
the primary — often it is simply absent.

#### Event mapping

| SES `notificationType` / `eventType` | State |
|---|---|
| `Send` | `accepted` |
| `Delivery` | `delivered` |
| `Bounce` (`Permanent`, `Transient`, `Undetermined`) | `bounced` |
| `Reject`, `Rendering Failure` | `bounced` |
| `Complaint` | `complained` |
| `DeliveryDelay` | recorded, no change |
| anything unrecognized | recorded, no change |

**Every bounce type maps to `bounced`, and that is a decision.** The state means "it did not
arrive", which is true of a full mailbox as well as a nonexistent address; `MailState` says so
explicitly ("hard or soft"), and Brevo's `softBounce` already maps the same way, so switching
providers does not silently change what an operator is looking at. Ranking is the other half
of the reason: ordering is decided by `MailState::supersedes()` and `bounced` outranks
`delivered`, so a softer state would have to sit *below* `delivered` to stay correctable — and
then a transient bounce on a message that never arrived would be invisible.

What the distinction is worth is diagnosis, so it is kept rather than discarded:
`bounce_type` and `bounce_subtype` are recorded on the event payload, where an operator can
tell `Transient`/`MailboxFull` from `Permanent`/`General` and only one of those is worth
chasing.

An event is stamped with its own time (`bounce.timestamp`, `complaint.timestamp`, …), not
`mail.timestamp`, which is when the message was *sent* and is identical on every notification
about it. That value becomes `state_changed_at`, so the send time would stamp a bounce
backwards to send and break both the operator timeline and the 24-hour windows the backlog
probe and `esign:mail:backlog` read.

Feedback about a message id with no row is recorded as an orphan rather than answered 404, for
the same reasons as Brevo's.

#### Turning SES feedback on

1. Create the SNS topic and set its `SignatureVersion` to 2.
2. Point the SES identity's bounce/complaint/delivery notifications, or a configuration set's
   event destination, at the topic. Enable "include original headers" if you have rows sent
   before the message-id fix above.
3. Subscribe `https://<your-host>/webhooks/mail/ses` to the topic, and confirm the
   subscription in the SNS console.
4. Set `ESIGN_MAIL_SES_TOPIC_ARNS` to the topic ARN and deploy.
5. Send a real message and check `esign:mail:backlog --all` shows a `Delivery` event, not a
   `refused` one.

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
