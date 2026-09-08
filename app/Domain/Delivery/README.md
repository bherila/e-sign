# Delivery

See docs/ARCHITECTURE.md for what this module owns. Keep cross-module calls behind interfaces.

- `Outbound/` — the destination policy every outbound request to a stored URL passes before
  a packet leaves the process. Shared: the Evidence module's timestamp authority uses it too.
- `Webhooks/` — the transactional outbox, signing, retries, rotation, replay, and the
  administration commands. Contract and runbook: `docs/delivery/webhooks.md`.
- `Health/` — readiness probes. `docs/operations/health.md`.
