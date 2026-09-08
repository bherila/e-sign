# Guest recipient access

External signers are recipients, not application administrators. They have no account, no
workspace membership, and no password, and they still have to be able to read an agreement and
execute it. This is how.

| | |
|---|---|
| Credentials and sessions | `app/Domain/Signing/Sessions/` |
| Consent and signature capture | `app/Domain/Signing/Capture/` |
| HTTP surface | `app/Http/Controllers/Signing/`, `app/Http/Requests/Signing/`, `routes/signing.php` |
| The signer's page | `resources/js/signing/`, `resources/views/signing/` |
| Consent notice | `resources/views/signing/consent.md` |
| Configuration | `config/esign.php`, `signing.*` |
| Tests | `tests/Feature/Signing/Guest*`, `tests/Feature/Signing/HarmlessGetTest.php`, `tests/Unit/Signing/` |
| Specification | `docs/HANDOFF.md` sections 5 and 8; `docs/signing/state-machine.md` |

Nothing here decides anything about signing. Every rule about who may act, when, what is
frozen, and what an acceptance means lives in `EnvelopeStateMachine`
(`docs/signing/state-machine.md`), which the guest UI, the native API, and the
Firma-compatible facade all call. This module answers one narrower question: *is this person
allowed to be here at all, and what did they actually see?*


## The flow

```
mail                GET /sign/{envelope}/{token}            landing page. reads three rows.
                    ─────────────────────────────           consumes nothing. safe for a scanner.
                              │  Continue
                              ▼
                    POST /sign/{envelope}/{token}/start     ── OTP required? ──▶ mails a code,
                    ─────────────────────────────────           re-renders with a code box
                              │                                        │
                              │  ◀───────────────────────── code accepted
                              ▼
                    consumes the invitation, writes a signing_sessions row,
                    sets the esign_signing cookie, 303 ▶
                              │
                              ▼
                    GET  /sign/{envelope}/session           the document, the fields, the consent
                    GET  /sign/{envelope}/session/document  the review revision's bytes
                    POST /sign/{envelope}/session/values    saves values. signs nothing.
                    POST /sign/{envelope}/session/accept    the assent
                    POST /sign/{envelope}/session/decline   the refusal
```

And the compatibility route, which is the only way a bare recipient identifier leads anywhere:

```
GET  /signing/{recipientPublicId}          a form asking for the address. reveals nothing.
POST /signing/{recipientPublicId}          mails a code, if the address is the right one
POST /signing/{recipientPublicId}/verify   mints an invitation, spends it, opens a session, 303 ▶
```


## GET is harmless, and why that is a hard rule

A recipient's mail provider fetches every URL in a message before the recipient sees it. So do
link expanders, archivers, corporate mail gateways, and the recipient's own browser
prefetching on hover. Any GET with a side effect on this surface is therefore a signature
applied by a robot, and AGENTS.md states the rule without qualification: *signing pages never
apply signatures, consume one-shot tokens, or advance recipients on GET.*

There are four GETs, they read rows and render, and
`tests/Feature/Signing/HarmlessGetTest.php` fetches every one of them twice and asserts the
envelope, every recipient, every field value, every attestation, every invitation, every OTP
challenge, and the outbox are byte-identical afterwards. A separate test pins the list of GET
route names, so adding a fifth forces somebody to come back here.

The one thing a GET does change is a live session's `expires_at`, which slides on each
authorized request. That is a deliberate exception and is called out in the test: it changes
when a browser tab stops working and nothing about the agreement. The alternative — a fixed
window — logs out the person reading a forty-page contract carefully, who is the last person
this product should interrupt.


## The two credentials

### The invitation

`recipient_invitations` holds a SHA-256 verifier and never the token. The plaintext is 256 bits
from `random_bytes`, base64url-encoded, returned exactly once to whatever will mail it, and
after that no database dump, support screen, or log line can reconstruct it.

The hash is a bare SHA-256 rather than a password hash, deliberately. bcrypt and argon2 exist
to slow an attacker guessing a low-entropy human secret; there is nothing here to guess, so a
slow KDF buys no security and costs a unique index — lookup becomes a scan that verifies every
row instead of a single indexed read.

- **Scope.** One recipient, one envelope. `envelope_id` is stored alongside `recipient_id` and
  checked against the URL, so a token minted for one envelope cannot be replayed against
  another by editing the identifier next to it.
- **Expiry.** `signing.invitation_ttl_hours`, 168 by default. Long enough to survive a weekend
  and an out-of-office; short enough that a forwarded mail from last quarter is not a signing
  credential.
- **Rotation.** Issuing revokes every live invitation for that recipient. That is what makes
  "resend the link" mean something: the address that received the earlier mail loses the
  ability to sign. Keeping both alive would make every resend widen the set of people who can
  execute the agreement. `InvitationIssuer::reissue()` is the same operation named for what a
  sender is doing, so the audit trail can say which happened.
- **One shot.** `POST …/start` consumes it, with a compare-and-swap on `consumed_at IS NULL`,
  so two simultaneous submissions produce one session and the loser is told the credential is
  spent.

### The session

`signing_sessions` holds a verifier for the value in the `esign_signing` cookie, the same way
and for the same reason.

| Cookie attribute | Value | Why |
|---|---|---|
| `Domain` | unset | Host-only. A registrable-domain cookie goes to every subdomain, including ones this application does not run on. |
| `Path` | `/sign` | The compatibility surface at `/signing/…` does not match — path matching needs a `/` boundary — so it never sees a credential it has no use for. |
| `HttpOnly` | yes | The page runs JavaScript; the credential is not JavaScript's business. |
| `SameSite` | `Lax` | The invitation arrives as a link in a mail client: a cross-site top-level GET. `Strict` drops the cookie on exactly that navigation. |
| `Secure` | production, or wherever `session.secure` is set | Local development over `http://localhost` still has to work. |
| `Expires` | session cookie | Closing the browser drops it. The row is the authority and expires on its own clock. |

`signing_sessions.public_id` is what `recipient_attestations.session_ref` records. It is a
ULID and deliberately not the cookie value: the cookie is a bearer credential and must not
appear in an evidence record, and the evidence record has to stay readable after the
credential is gone. It is also the idempotency key for an acceptance
(`docs/ARCHITECTURE.md` invariant 7), so a double-submitted form produces one attestation.

`RequireSigningSession` is the single gate. It checks the cookie, the row's liveness, and that
the row's envelope is the envelope in the URL — a live session for one agreement is not an
authorization for another. It deliberately does *not* check eligibility: somebody who has
already signed, or whose turn has not come, still needs to load the page and be told so.


## Mailbox codes

Off by default. Turned on per deployment (`signing.require_otp`), per workspace
(`workspaces.require_otp`), or per envelope (`envelopes.require_otp`); null means "not decided
here" and resolution is envelope, then workspace, then deployment.

**Be precise about what it is worth.** The invitation already went to that address, so a code
sent to the same address demonstrates *continued* access to the same mailbox. It is a second
check on one factor, not a second factor, and `docs/HANDOFF.md` section 9 is explicit that
email OTP plus an organizational seal is not an eIDAS advanced or qualified human signature.
The evidence model records it as `email_otp` and grades nothing.

Where it does help is the case it was added for: a forwarded invitation. Somebody sent the mail
by the recipient can follow the link; they cannot receive the code, because the code goes to
the address on the envelope and never to the address that asked for it.

- Six digits, `random_int`, ten minutes, five attempts.
- The digest is **keyed** (`KeyedDigest`), because six digits is a twenty-bit space and an
  unkeyed hash of a leaked table is reversed in milliseconds.
- Attempts are counted on the row, so parallel requests share the budget, and reaching the
  ceiling burns the challenge rather than pausing it.
- Issuing a new code burns the outstanding one: two live codes double the guessing surface and
  make "the code I was just sent" ambiguous.
- `session_start` and `legacy_resolve` codes are not interchangeable. The legacy code stands in
  for a credential the caller does not have; the session-start code sits on top of one they do.
  Letting either satisfy the other would make the weaker the effective bar for both.
- Rate limits, all through Laravel's `RateLimiter` and all keyed on digests rather than on the
  address or the client IP: issuance per destination address, issuance per client address,
  verification per client address.

**A queued code is readable by an operator, and only a queued one.** The transactional outbox
renders from a persisted context — that is what makes delivery survive a crash — so the code
has to sit in `outbound_mails.context` between the moment it is enqueued and the moment the
message goes. `OutboundMail::markSentToProvider()` then drops it.

That window is seconds under a queue worker and up to one cron interval on the shared-hosting
profile. It used to be forever: `outbound_mails` has no pruner and `RecipientEraser` rewrites
only the two name fields, so every code ever mailed stayed in the database and in every backup
— while this page said "until the row is pruned"
([`docs/security/review-2026-09.md`](../security/review-2026-09.md), finding D-4).

What remains is stated rather than glossed: the check is aimed at somebody holding a forwarded
link, not at the host administrator, and `docs/HANDOFF.md` section 8 already requires saying
that a compromised host is outside what this evidence model defends against.


## The compatibility resolver

The provider this application is compatible with addresses signing by recipient id alone:
possession of the identifier is the authorization. `docs/HANDOFF.md` section 8 refuses that —
"A public recipient UUID alone must not authorize signing … Do not sacrifice authorization to
preserve a URL construction shortcut" — and names the one way out: "A legacy
`/signing/{recipientId}` resolver can require mailbox verification."

So the identifier reaches a form and nothing else. The page shows no agreement title, no sender,
no recipient name, and no address, because anyone holding the identifier would otherwise learn
who is contracting with whom. The response after an address is submitted is byte-identical
whether the address matched or not, and a wrong code and a code that was never issued produce
the same message.

On success the resolver mints a fresh invitation and spends it in the same request, then
redirects into the ordinary session page. Doing it here rather than bouncing through the
landing page means the mailbox check that just happened is not asked for twice, and the
credential never reaches a page, a redirect, or a log. `signing_sessions.invitation_id` keeps
the chain readable afterwards. Minting also revokes any live invitation, which is the honest
consequence: this person has proved mailbox access now, and the older link in that mailbox
stops working.


## Consent and capture

The consent notice is `resources/views/signing/consent.md` — a repository file, reviewed and
diffed like code, not a database row an administrator could rewrite between somebody reading it
and the attestation recording that they read it.

Which *version* was displayed is a different question, and the envelope already answers it:
`envelopes.consent_policy_version` is snapshotted at creation, checked by the state machine on
acceptance, and copied onto the attestation. `signing.consent_policy_version` says which
version the file on disk is; when the two disagree the page says so in place rather than
rendering current wording under an older version's name.

Two tick boxes, not one. "I have read the notice" and "I intend this to be my signature and to
be bound by it" are different statements, and `docs/HANDOFF.md` section 8 asks for explicit
adoption *and* intent. A single combined "I agree" would leave no record of which was made.

Signature and initials fields are captured two ways:

- **Typed** — the recipient's name rendered in a plain system font. This is the tab that opens.
- **Drawn** — pointer events on a canvas, mouse, pen, or touch.

Both produce a PNG data URL client-side, and both are then decoded, measured, and **re-encoded**
server-side by `SignatureImage`. Re-encoding rather than validating is the point: a validated
file stored verbatim carries its XMP block, its ICC profile, its comment segments, and anything
else that was in it into the sealed PDF, and only a trip through a pixel buffer removes that.

| Input | Outcome |
|---|---|
| PNG or JPEG within the limits | decoded, re-encoded as PNG, stored |
| `https://…/signature.png` | refused. A remote URL is a request somebody else's host serves, later, at a time nobody chose. |
| `data:image/svg+xml,…` | refused by name. SVG is a document format with scripting and external references; there is no subset of it that is "just a picture". |
| a permitted format announced as the other one | refused. Declared and sniffed types must agree. |
| HTML, or anything GD cannot decode | refused |
| over `signing.max_signature_image_bytes` (200 KB) or 2000×800 | refused, with the numbers |

There is no sanitizer anywhere and no "clean it up and carry on" branch. An allowlist of two
raster formats that both round-trip through a pixel buffer is a boundary that can be reasoned
about; a sanitizer is a promise to keep up with everyone who has ever embedded something in a
file.

**A drawing is not a signature.** `isBlank()` measures total stroke path length against a
threshold, not "are there any points", so a stray tap, a pocket touch, or a two-pixel scratch
cannot be adopted. Adoption is a separate deliberate press. `docs/HANDOFF.md` section 8: never
record a successful signature merely because a canvas is nonempty.

`signing_date` is never editable and never submitted. Its value is the owning recipient's
`recipient_attestations.accepted_at`, derived from an immutable attestation rather than copied
into a mutable table where the two could disagree.


## What the evidence record captures

An acceptance writes one `recipient_attestations` row, and that row *is* the acceptance
(`docs/signing/state-machine.md`). What this module contributes to it:

| Field | From | Note |
|---|---|---|
| `session_ref` | `signing_sessions.public_id` | Also the idempotency key. |
| `verification_method` | the session | `email_link` or `email_otp`. Not a claim about identity. |
| `consent_policy_version` | the envelope's snapshot, echoed by the form and checked | A mismatch is `ConsentMismatch`, never a silent acceptance of current text. |
| `material_values_sha256` | echoed from what the page rendered | Refused if it has moved — `StaleReview`, a 409, and a page asking the person to read it again. |
| `accepted_at` | the server clock, UTC | Never a client timestamp. |
| `client_evidence` | `ClientEvidence`'s allowlist | `ip`, `user_agent`, `accept_language`, `channel`. Nothing else. |

`client_evidence` is an allowlist, not a scrubber: an allowlist only has to be right about what
it keeps. Deliberately absent are cookies, session identifiers, the referrer (this surface sends
none), and anything a page could compute about fonts, canvases, or hardware. It corroborates a
session and is **never** identity proof — `docs/HANDOFF.md` section 8 says so and nothing here
or downstream treats it otherwise.

The session row itself stores `ip_hash` and `user_agent_hash`, keyed digests rather than values,
so it can answer "same place the session started from?" without accumulating a browsing history.
The unhashed address reaches the attestation once, at the moment of assent, because a hash
corroborates nothing to a later reader.

Other parties' email addresses never reach the signing page. The copied field schema carries
them, so the payload replaces each with a `…@redacted.invalid` placeholder — RFC 2606 reserves
`.invalid`, and a visible placeholder says the value was withheld where a plausible substitute
would be a lie in a document somebody is about to sign against.


## Response hardening

Every response in `routes/signing.php` — the streamed PDF included — passes
`SigningSecurityHeaders`:

- `Referrer-Policy: no-referrer`. The invitation credential is in the URL path, so anything this
  page fetched from another origin would hand that origin the credential in a `Referer` header.
- `X-Frame-Options: DENY`, and `frame-ancestors 'none'` in the policy.
- A CSP with **no third-party origin**: `default-src 'self'`, `script-src 'self'` with no
  `'unsafe-inline'` or `'unsafe-eval'`, `object-src 'none'`, `base-uri 'none'`,
  `form-action 'self'`. `img-src` adds `data:` and `blob:` because a captured signature *is* a
  data URL and PDF.js paints through blobs; `worker-src` adds `blob:` because PDF.js constructs
  a real worker from one; `style-src` adds `'unsafe-inline'` because React writes element
  `style` attributes for the field overlay.
- `Cache-Control: private, no-store`, `X-Content-Type-Options: nosniff`, and a
  `Permissions-Policy` that switches off camera, microphone, geolocation, and the rest.

The application's global CSP names an analytics host. It does not reach this surface: Spatie's
`AddCspHeaders` returns early when a response already carries a policy, and the route middleware
— running inside it — has set one by then. A test asserts the resulting header rather than
trusting that.

PDF.js is served from this origin: the worker is bundled and its four runtime resource
directories are copied into `public/vendor/pdfjs`. Leaving any of them unset makes the library
fall back to its published CDN, which is forbidden here above all pages.

CSRF protection comes from the `web` group and a test asserts that every signing POST route
gathers `PreventRequestForgery`.

**The resolver's refusals are uniform, including its rate limits.** The per-destination-address
issuance ceiling can only be reached by an address that *matched*, so surfacing it as a 429 made
the endpoint an address-confirmation oracle: post a guessed address six times, and a 429 on the
sixth meant the guess was right. The per-client ceiling is reported, because it is a fact about
the caller; a refusal from the matched branch is swallowed and the neutral page returned, with
the ceiling still enforced. See finding G-1 in
[`docs/security/review-2026-09.md`](../security/review-2026-09.md).

**Return destinations** are validated, never reflected. A `?return=` value is used only when its
host appears in `signing.return_url_allowlist`, which is deployment configuration and empty by
default. Everything else is *ignored* — no error, because a distinguishable rejection would turn
the parameter into an oracle for the allowlist's contents and would show a signer a validation
failure about somebody else's integration seconds after they signed. Host matching is exact and
case-insensitive; there is no suffix matching, because `*.example.test` reads as a convenience
and behaves as a delegation to whoever can register a subdomain.

**Tokens are never logged.** No code path in this module writes one to a log, an exception
message, or an audit payload, and `SigningSecurityHeadersTest` asserts with `Log::spy()` that a
full landing-plus-start-plus-refusal sequence logs nothing at all.


## Threat model

| Threat | What stops it | Residual |
|---|---|---|
| A mail scanner signs the agreement | No GET has a side effect; the assent is a CSRF-protected POST behind two tick boxes. | None known. |
| A forwarded invitation is used by the wrong person | Optional mailbox OTP; reissuing revokes the forwarded credential. | With OTP off, possession of the link is the bar. That is the stated assurance class, not an accident. |
| Enumerating recipients or envelopes | ULIDs; a 43-character token pattern refused at the router; one identical refusal page for every reason; rate limits per client address. | A determined attacker learns nothing from the refusal page but does learn a ULID exists if they guess one. A ULID is 48 bits of millisecond timestamp and **80 bits** of randomness, not 128 — the creation time of an envelope is often approximately known. 80 bits is still out of reach, and the correction matters because a threat model that rounds its own numbers up is not one to rely on. `GET /signing/{recipient}` carries no rate limit, so probing is free; that is an accepted residual, recorded as G-3 in [`docs/security/review-2026-09.md`](../security/review-2026-09.md). |
| Guessing a token | 256 bits, plus a per-client start limiter. | None practical. |
| Guessing an OTP | 20-bit space, but five attempts per challenge, burned on exhaustion, and rate-limited issuance. | A patient attacker who can request many codes gets many five-attempt windows; the per-address issuance limit is what bounds that. |
| Replaying a session against another envelope | `RequireSigningSession` compares the session's envelope with the URL's. | None known. |
| Stealing the cookie via XSS | CSP with no inline script, `HttpOnly`. | An XSS in a dependency would still be serious; the CSP is the mitigation, not a guarantee. |
| Leaking the token through `Referer` | `Referrer-Policy: no-referrer`, and no third-party origin to leak to. | **The token is in a URL path, so it appears in web-server access logs and in any reverse proxy's.** Operators who retain access logs retain live credentials for their TTL. Nothing in the application can fix that; a deployment that cares should exclude `/sign/*` from access logging. |
| Open redirect after signing | Host allowlist, empty by default, plus `form-action 'self'`. | None known. |
| Signing something other than what was displayed | The acceptance echoes the material digest and envelope version; the state machine refuses if either moved. | None known. |
| An operator reading a live OTP | Not prevented. | Stated above and in `docs/HANDOFF.md` section 8: a compromised host or a hostile administrator is outside this evidence model. |
| A signature image carrying a payload into the sealed PDF | Decode and re-encode through a pixel buffer; two raster formats only. | A GD decoder vulnerability would be a real exposure; the byte and dimension ceilings bound it. |


## Mobile and accessibility

- The layout is a single column, comfortable at 375px, with its measure capped on a desktop.
  It is one column at every width deliberately: a two-pane layout collapses on a phone anyway
  and the collapse decides the reading order for you. Read it, fill it in, then agree, is the
  order the act itself has.
- **Pinch-zoom is not blocked.** The viewport meta names `width=device-width, initial-scale=1`
  and stops there — no `maximum-scale`, no `user-scalable=no`. Somebody reading a contract on a
  phone has to be able to magnify the small print, and blocking that on a page whose whole
  purpose is informed agreement would be indefensible.
- `touch-action: none` is set on the signature canvas alone, so drawing works with a finger
  while the rest of the page keeps native scrolling and zoom.
- Interactive controls carry a 44px minimum height. Checkboxes are 24px with a full-width
  label as the hit area.
- **Typing is the default signature path, not a fallback.** Somebody using a keyboard, a switch
  device, or a screen reader cannot draw, and a signing flow whose primary gesture is a mouse
  drag excludes them from agreeing to a contract. The consent notice says both paths carry the
  same weight, which is true: the mark is not what makes the signature.
- The typed face is a system stack. A cursive webfont would be either a third-party origin
  (forbidden) or a bundled asset pretending a keyboard produced handwriting.
- Field boxes on the rendered document are real `<button>`s that move focus to the matching
  input; other parties' fields are `aria-hidden` decoration, because they are not interactive
  and announcing them twice would bury the ones that are.
- Save state is announced with `aria-live="polite"`, so a screen reader hears that changes were
  saved without the focus moving.
- The document canvas is `aria-hidden`: it is a raster and conveys nothing to a screen reader.
  A signer who cannot see the rendered page needs the source PDF, which the sender provides —
  a genuine gap this page does not close and does not pretend to.
- Without JavaScript the page says so and says nothing has been signed. There is no useful
  no-script form of overlaying fields on a rendered PDF, and a partial one that collected a
  signature without showing the document would be worse than none.


## Configuration

```
ESIGN_SIGNING_INVITATION_TTL_HOURS=168
ESIGN_SIGNING_SESSION_TTL_MINUTES=120
ESIGN_SIGNING_REQUIRE_OTP=false
ESIGN_SIGNING_OTP_TTL_MINUTES=10
ESIGN_SIGNING_OTP_MAX_ATTEMPTS=5
ESIGN_SIGNING_OTP_PER_ADDRESS_PER_HOUR=5
ESIGN_SIGNING_OTP_PER_IP_PER_HOUR=20
ESIGN_SIGNING_OTP_VERIFY_PER_IP_PER_HOUR=30
ESIGN_SIGNING_START_PER_IP_PER_HOUR=30
ESIGN_SIGNING_MAX_SIGNATURE_IMAGE_BYTES=204800
ESIGN_SIGNING_MAX_SIGNATURE_IMAGE_WIDTH=2000
ESIGN_SIGNING_MAX_SIGNATURE_IMAGE_HEIGHT=800
ESIGN_SIGNING_RETURN_URL_ALLOWLIST=
```

`KeyedDigest` derives its key from `APP_KEY`. Rotating `APP_KEY` invalidates every session
digest and every outstanding OTP, which is correct — they are corroboration for live sessions
and ten-minute codes, not records that have to outlive a key rotation. Everything that must
survive one (the attestation chain, the material digest) uses an unkeyed hash of high-entropy
input instead.


## Deferred

| To | What |
|---|---|
| **#36 delivery binding** | Nothing here mails anything. `App\Domain\Delivery\Events\SigningUrlMinter` is the port the Delivery module calls for an invitation's one link, `PlaceholderSigningUrlMinter` refuses loudly until something is bound to it, and `InvitationIssuer::issue()` is what that binding will return — an absolute, credential-bearing URL with no query string. Binding the two is #36's, and deliberately not done here: it changes when live credentials start being minted, which is a decision the module that sends mail should make visibly. |
| **Reminders** | `MailKind::Reminder` exists and `ReminderScheduler` schedules it. A reminder that re-sends the *same* link cannot work here, because the original invitation is one-shot: whoever wires #36 has to reissue on reminder, which revokes the earlier credential. That is correct behaviour and worth stating out loud, because it means an old reminder in a mailbox stops working. |
| **#28 finalization** | The confirmation page promises a sealed copy once everyone signs. Producing, validating, storing, and delivering it is the finalizer's. |
