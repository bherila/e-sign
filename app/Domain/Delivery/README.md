# Delivery

See docs/ARCHITECTURE.md for what this module owns. Keep cross-module calls behind interfaces.

- `Outbound/` — the destination policy every outbound request to a stored URL passes before
  a packet leaves the process. Shared: the Evidence module's timestamp authority uses it too.
- `Webhooks/` — the transactional outbox, signing, retries, rotation, replay, and the
  administration commands. Contract and runbook: `docs/delivery/webhooks.md`.
- `Mail/` — the transactional mail outbox: states, the enqueue and send path, provider
  feedback, and the operator commands. `docs/delivery/mail.md`.
- `Events/` — the join between the signing state machine and the two outboxes: the
  `signing_request.*` payload builder, the event sink, the mail fan-out, and the scheduled
  reminder and expiry commands. `docs/delivery/envelope-events.md`.
- `Health/` — readiness probes. `docs/operations/health.md`.

## Mail outbox

Two things are load-bearing and easy to undo by accident:

- **`queued` is not sent and `sent_to_provider` is not delivered.** Only provider feedback
  writes `delivered`, `bounced`, or `complained`. Nothing else in this module writes them.
- **A non-delivering mailer in production is a refusal, not a warning.**
  `ProductionMailerGuard` holds the allowlist as a class constant rather than configuration,
  because the mistake it prevents would otherwise be reachable by editing mail settings.

`Mail/` renders only from `MailContext`, a plain data object. That is what lets the outbox
exist before envelopes do: whatever produces a message flattens what it wants said into that
object, and the Mailables never learn what an envelope is.

The SES feedback endpoint fails closed — see `Mail/Feedback/RejectingSnsMessageVerifier` for
what implementing SNS signature verification involves and why it has not been hand-rolled.

## Envelope events

The rule that shapes `Events/`: the webhook row is written **inside** the transition's
transaction and the mail is scheduled **after** it commits. An event and its state change are
one atomic fact; a message is not, because mail has no undo.

`SigningUrlMinter` throws when no link can be issued and `DownloadUrlMinter` returns null when
there is nothing to link to. That asymmetry is deliberate: an invitation without a working
link is a lie told to somebody with no support path, and a completion notice without one is
still true.
