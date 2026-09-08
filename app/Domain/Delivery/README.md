# Delivery

See docs/ARCHITECTURE.md for what this module owns. Keep cross-module calls behind interfaces.

## Layout

| Directory | Contents |
|---|---|
| `Health/` | Readiness probes and the report they aggregate into, wired in `App\Providers\HealthServiceProvider`. |
| `Mail/` | The transactional mail outbox: states, the enqueue and send path, provider feedback, and the operator commands. |

## Mail outbox

States, the send path, both provider webhooks, the redaction rules, and what "delivered"
means and does not mean are documented in
[docs/delivery/mail.md](../../../docs/delivery/mail.md).

Two things are load-bearing and easy to undo by accident:

- **`queued` is not sent and `sent_to_provider` is not delivered.** Only provider feedback
  writes `delivered`, `bounced`, or `complained`. Nothing in this module writes them on its
  own.
- **A non-delivering mailer in production is a refusal, not a warning.**
  `ProductionMailerGuard` holds the allowlist as a class constant rather than configuration,
  because the mistake it prevents would otherwise be reachable by editing mail settings.

`Mail/` renders only from `MailContext`, a plain data object. That is what lets the outbox
exist before envelopes do: whatever produces a message flattens what it wants said into that
object, and the Mailables never learn what an envelope is.

The SES feedback endpoint fails closed — see `Mail/Feedback/RejectingSnsMessageVerifier` for
what implementing SNS signature verification involves and why it has not been hand-rolled.
