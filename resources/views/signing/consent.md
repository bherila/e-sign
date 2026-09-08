<!--
    The electronic-signature consent notice shown to every guest before they can assent.

    This file is the notice. It is versioned with the code, and the version string a
    deployment declares for it lives in ESIGN_CONSENT_POLICY_VERSION; an envelope snapshots
    that string at creation and the attestation records the snapshot, so "which text was this
    person shown" is answerable years later from the repository history.

    Two rules govern edits, both from AGENTS.md and docs/HANDOFF.md section 9:

    1. Honest language. People provide electronic signatures and assent; the service seals
       the finished PDF under its own certificate. Do not describe the seal as a per-signer
       certificate, do not claim eIDAS advanced or qualified signatures, and do not promise
       enforceability.
    2. Any substantive change is a new version. Bump ESIGN_CONSENT_POLICY_VERSION in the same
       change, so an envelope created afterwards records the new string and this page stops
       reporting that the text and the version agree.

    The wording below is synthetic sample text for a self-hosted product, not legal advice
    and not counsel-approved copy for any particular deployment.
-->

## Signing this agreement electronically

You are about to sign this agreement electronically instead of on paper. Please read this
short notice first.

### What your signature is

When you tick the box and choose **I agree**, you are placing your electronic signature on
this document. The mark you draw or type is displayed on the agreement, but it is not what
makes the signature — your deliberate act of agreeing is. We record what you were shown, what
you filled in, when you agreed, and how you reached this page.

We do not issue you a personal signing certificate, and we do not check who you are. Reaching
this page means somebody with access to the invited mailbox opened the link we sent, and, if
this agreement was set up to ask for one, entered a code we sent to that same address. That
shows control of a mailbox. It is not proof of identity, and nothing here claims to be an
identity check.

### What happens to the document

Once everybody has signed, the finished PDF is sealed with a certificate belonging to this
service. The seal is what lets a reader detect whether the finished document has been altered
since. It belongs to the service, not to you — it is not a personal certificate, and it does
not represent an eIDAS advanced or qualified electronic signature. Which assurance level was
applied is recorded alongside the document.

### What we keep

Alongside your signature we keep a record of this session: the version of this notice you
were shown, the exact document and field values you were shown, the time the server accepted
your agreement, the network address and browser description your request arrived with, and
whether a mailed code was used. The network address and browser description corroborate the
session; they are not treated as proof of who you are.

### Your choices

- You can decline. There is a **Decline** option on this page, and using it stops the
  agreement for everybody. Closing the page or ignoring the invitation signs nothing.
- You can ask for a paper or PDF copy before you sign. Every party gets a copy of the sealed
  PDF once signing is complete, and you can ask the sender for one at any time.
- If you cannot use a mouse or a touchscreen, type your name instead of drawing it. The typed
  option carries exactly the same weight as the drawn one.

### If something looks wrong

If the document is not what you expected, or a field has been filled in with something you did
not supply, do not sign. Contact the sender. A correction is a new version of the agreement
and needs fresh signatures from everyone — nothing you have already agreed to can be edited
afterwards.
