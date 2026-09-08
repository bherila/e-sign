# 0003. First release seals with a self-issued certificate and a public RFC 3161 TSA

**Status:** accepted, 2026-09-07 (owner decision)

## Context

The service seal needs a signing certificate and, for PAdES B-T, a timestamp authority.
CA-issued document-signing certificates (AATL) give relying-party trust in viewers but add
cost and key-custody requirements before the pipeline is proven.

## Decision

Each instance seals with a self-issued organizational certificate whose subject discloses the
operator, and obtains B-T timestamps from a configured public RFC 3161 TSA. The certificate and
TSA are configuration, versioned by key id, and replaceable without code changes.

## Consequences

- Artifacts are cryptographically verifiable but viewers will not show a trusted seal until the
  operator installs a CA-issued certificate. Documentation states this plainly.
- Timestamp trust depends on the chosen TSA's trust arrangements; the app validates responses
  and fails closed, it does not vouch for the TSA.
- Upgrading to a CA-issued certificate is a key rotation, not a redesign.
