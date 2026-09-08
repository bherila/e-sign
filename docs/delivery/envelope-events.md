# Envelope events: what goes on the wire, and who gets told

Every state change an envelope makes produces exactly two kinds of consequence, and the
difference between them is the whole of this document:

- **A webhook event, written inside the transaction that made the change.** It commits with
  the transition or it rolls back with it. Nobody is ever told about a transition that did
  not happen, and no transition happens without its event.
- **Mail, scheduled after that transaction commits.** Mail has no undo. A message enqueued
  inside a transaction that later rolls back has already told somebody something untrue.

| | |
|---|---|
| Port | `App\Domain\Signing\Contracts\EnvelopeEventSink` |
| Implementation | `app/Domain/Delivery/Events/DeliveryEnvelopeEventSink.php` |
| Composed with | `App\Domain\Signing\Envelopes\AuditEnvelopeEventSink`, via `CompositeEnvelopeEventSink` |
| Payload | `app/Domain/Delivery/Events/SigningRequestPayload.php` |
| Mail fan-out | `app/Domain/Delivery/Events/EnvelopeMailScheduler.php`, run by `Jobs/ScheduleEnvelopeMail` |
| Tests | `tests/Feature/Delivery/Events/`, `tests/Unit/Delivery/Events/` |
| Specification | `docs/HANDOFF.md` §11; `docs/signing/state-machine.md`; `docs/compatibility/firma-capability-matrix.md` |

## The map

| Transition | Outbox event(s) | Mail | To whom |
|---|---|---|---|
| envelope created | `signing_request.created` | — | nobody: a draft has been shown to no one |
| `send()` | `signing_request.sent` | `invitation` | every recipient the send released |
| `accept()` | `signing_request.recipient.signed` | `invitation` | the recipients that acceptance released, and nobody else |
| `decline()` | `signing_request.recipient.declined` **and** `esign.envelope.declined` | `declined` | the sender |
| `cancel()` | `signing_request.cancelled` | `cancelled` | every invited recipient, and the sender |
| `expire()` | `signing_request.expired` | `expired` | every invited recipient, and the sender |
| `markCompleted()` | `signing_request.completed` | `completed` | every recipient, and the sender |
| `markFinalizationFailed()` | `esign.envelope.finalization.failed` | `admin_failure` | the workspace's owners |
| `esign:signing:remind` | — | `reminder` | recipients still being waited on |

Four of those rows are decisions rather than mechanics.

**An acceptance is not a completion.** `signing_request.recipient.signed` releases the next
stage and produces invitations. It produces nothing else — no "one down, one to go" notice,
which would be an event the profile does not have, and no completion, which is not true yet
(`docs/HANDOFF.md` §11).

**A decline emits two names and one message.** The profile has a recipient-level decline and
no envelope-level one (capability matrix, disagreement D12), so both go out: theirs for a
subscriber that switches on it, ours for anything that needs the envelope fact without
inferring it. Only the envelope-level event schedules mail; sending from both would double
every notice. The message goes to the sender alone, because it carries the reason the
recipient gave and forwarding that to the other parties is not the sender's decision to make
for them.

**Nobody who was never invited is told anything.** Cancellation and expiry reach the people
carrying an `invited_at`, which in sequential mode is usually not everybody. Writing to a
later signer would disclose an agreement's existence, its title, and its parties to somebody
the sender had not yet reached.

**A failed finalization is an operational message, not a signing one.** The signers are told
nothing: from their side the agreement is signed and waiting, the state machine keeps it
visibly `finalization_failed` rather than completed, and a retry that succeeds needs no
correction to have been mailed out. Workspace owners are told, because `owner` is the role
that can authorise a retry.

## The payload

One builder, `SigningRequestPayload`, used by the event sink and by the Firma-compatible
facade, so a receiver polling `GET /signing-requests/{id}` and a receiver subscribing to
webhooks cannot be told two different stories about the same envelope.

```json
{
  "signing_request": {
    "id": "01K4S6ZT4Q1S9WB0Y8V3H2N7XD",
    "name": "Mutual Nondisclosure Agreement",
    "companies_workspaces_id": "01K4S6ZQ8F0J5C7A2M6P4R9TQE",
    "status": { "sent": true, "finished": false, "cancelled": false, "declined": false, "expired": false },
    "timestamps": {
      "created_on": "…", "sent_on": "…", "finished_on": null, "cancelled_on": null,
      "declined_on": null, "last_changed_on": "…", "last_signing_action_on": "…"
    },
    "expiration_hours": 168,
    "expires_at": "…",
    "download": null
  },
  "recipients": [
    {
      "id": "…", "name": "…", "email": "…", "designation": "Signer", "order": 1,
      "finished_on": null, "declined_on": null, "decline_reason": null
    }
  ],
  "workspace": { "id": "…", "name": "…" }
}
```

- **`status` is an object of booleans**, not a string. Upstream carries three different
  status representations — a string enum on create, this object on the detail route, another
  string enum on download — and says several flags can be true at once for a terminal state.
  The consumer polls this one, and a cancelled envelope that was sent reports both.
- **`timestamps` uses the `_on` suffix** of `SigningRequestDetail`, never the `_date` suffix
  of the create response. Both exist upstream and neither may be normalised into the other.
- **`recipients[]` is scoped to the event.** Envelope-level events carry everybody;
  `signing_request.recipient.*` carries the one party the event is about, so a receiver
  handling "who just signed" does not have to diff two arrays to find out.
- **`finished_on` on a recipient is their signature**; `timestamps.finished_on` on the
  request is completion. Those are genuinely different moments here, later apart than
  upstream, because completion waits for a validated retrievable PDF.

### Three deliberate omissions

**`download` is never a URL.** A webhook body is encoded once, signed once, and replayed for
up to ~40 hours across the retry schedule. A link minted when the event was recorded is
either dead by the time it is read or long-lived enough to be a credential sitting in a
receiver's log. The field is `null` until a validated artifact exists and then reports only
that one does; the bytes come from the download endpoint, authorized per request.

**No `first_name` / `last_name`.** This product stores one display name. Splitting it would
be a guess about a person's name, and `docs/HANDOFF.md` §2 does not guess identity facts.

**`designation` is always `Signer`.** There is no approver or CC concept here yet, and
emitting a per-recipient authority that nothing enforces would be worse than emitting the
constant that is true.

## Links in messages

Two ports, deliberately asymmetric.

| Port | Missing value | Why |
|---|---|---|
| `SigningUrlMinter` | throws `SigningUrlUnavailable` | a signing link that goes nowhere makes the invitation a lie, and the audience — an external signer with no account — has no support path |
| `DownloadUrlMinter` | returns `null` | a completion notice without a link is still true, and `App\Mail\CompletedMail` says so |

Until guest access lands, `PlaceholderSigningUrlMinter` is bound and it refuses. That is not
a stub waiting to be forgotten: an implementation that returned a plausible URL would let a
deployment mail dead links and record them as sent, which is the "successful no-op" AGENTS.md
rules out. The refusal surfaces as a failed `ScheduleEnvelopeMail` job with the interface name
in the message, and nothing is written to the mail outbox, so no row claims a signer was
invited when they were not.

**What the guest-access module binds:**

```php
$this->app->bind(
    App\Domain\Delivery\Events\SigningUrlMinter::class,
    App\Domain\Signing\Sessions\InvitationIssuer::class,
);
```

The URL it returns must be absolute, `https` outside local development, and carry at most one
query parameter — `MailContext` enforces all three and refuses the message otherwise.

**One consequence worth knowing.** The scheduling job runs on the `esign.mail.queue` queue.
On a deployment configured with the `sync` queue driver, a refusal from the minter surfaces
in the caller's request *after* the transition has already committed: the transition stands,
the message does not. The web/worker/scheduler split in `docs/ARCHITECTURE.md` is the
supported shape, and there the refusal is a failed job an operator sees in the queue.

## Exactly once, without a lock

Two columns on `envelope_recipients` carry this, and both are Delivery's facts rather than
signing state — nothing in the state machine reads or writes them.

- **`invited_at`** is claimed with `UPDATE … WHERE invited_at IS NULL`, and the message is
  written only if that affected one row. Two workers running the same at-least-once job
  therefore produce one invitation. It is the same compare-and-swap discipline the state
  machine uses for transitions, and it works identically on SQLite, MySQL 8, and MariaDB
  (`docs/adr/0002-supported-databases.md`).
- **`last_reminded_at`** does the same for reminders.

The claim and the outbox row are written in one transaction, so a failure between them leaves
the recipient uninvited rather than silently skipped. The signing URL is minted *before*
either, because the placeholder minter throws and a throw must leave the recipient exactly as
uninvited as they were.

## Reminders and expiry

```bash
php artisan esign:signing:remind    # daily, from routes/console.php
php artisan esign:signing:expire    # hourly
```

Both are scheduled in `routes/console.php` with `withoutOverlapping()`, and both apply
conditions that are facts about a row rather than about the schedule, so an extra run does
nothing extra.

A reminder needs all of: the recipient is `active`; they were invited more than
`esign.signing.reminder_after_hours` ago (default 72); they have not been reminded within
`esign.signing.reminder_interval_hours` (default 24); and the envelope is still `sent` or
`in_progress` and not past its expiry. A `pending` later signer is not reminded, because
being reminded to do something the ordering guard would refuse is not a reminder.

`esign:signing:expire` cannot expire anything early. It finds envelopes whose `expires_at`
has passed and calls `EnvelopeStateMachine::expire()`, which re-checks the deadline under a
lock and refuses one that is not due — so the command is a prompt, not an authority. A
refusal is skipped rather than fatal: between the scan and the transition an envelope can be
cancelled, declined, or finished, and that race resolving the other way is a legal outcome.
An operator who wants to stop an envelope before its deadline is cancelling it, which records
a reason and says so.

## Related

- `docs/signing/state-machine.md` — which transitions exist and when the sink is called
- `docs/delivery/webhooks.md` — the envelope around this payload, signing, retries, replay
- `docs/delivery/mail.md` — states, templates, provider feedback
- `docs/compatibility/firma-capability-matrix.md` — the upstream contract and every
  disagreement with it
