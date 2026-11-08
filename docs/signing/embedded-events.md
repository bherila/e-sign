# Signing page events for an embedding page

A page that opens a guest signing page — in a popup today, or in a frame once a deployment
allows framing — can follow the signer's progress through `postMessage` announcements (issue
#168). This is the contract.

| | |
|---|---|
| Server half | `App\Domain\Signing\Sessions\SigningEmbedEvents`, `resources/views/layouts/signing.blade.php` |
| Browser half | `resources/js/signing/embedEvents.ts`, `resources/js/signing-events.ts`, `resources/js/signing/SigningPage.tsx` |
| Configuration | `ESIGN_SIGNING_EMBED_ORIGINS` → `config('esign.signing.embed_origins')` |
| Tests | `tests/Feature/Signing/SigningEmbedEventsTest.php`, `resources/js/signing/embedEvents.test.ts` |

## Nothing is announced unless you ask

`ESIGN_SIGNING_EMBED_ORIGINS` is a comma-separated list of **exact** origins, as a browser
reports `event.origin`: `https://app.example.com`, `https://app.example.com:8443`. HTTPS only,
with `http://localhost` and `http://127.0.0.1` allowed for development. A path, a trailing
slash, a wildcard, credentials, or plain HTTP to any other host is dropped, not interpreted.

Empty — the default — means signing pages carry no event attribute, load no event script, and
post nothing anywhere.

Each message is posted to the framing parent, or in a popup to `window.opener`, **once per
configured origin with that origin as the `targetOrigin`**. Never `"*"`. A page served from any
other origin receives nothing, even if it opened the signing page.

## Announcing is not framing

The signing pages still send `frame-ancestors 'none'` and `X-Frame-Options: DENY`
(`App\Http\Middleware\SigningSecurityHeaders`). Allowing a deployment to frame its signing
pages is a separate decision with its own clickjacking analysis, and this contract does not
make it. Until it is made, the contract is usable from a popup: open the signing link with
`window.open()` from an allowlisted origin and listen on that window.

## Messages

Every message is an object:

```json
{
  "source": "esign-signing",
  "version": 1,
  "type": "signing.loaded",
  "timestamp": "2026-11-08T12:00:00.000Z",
  "envelope_id": "01J…",
  "recipient_id": "01J…",
  "total_fields": 3
}
```

`envelope_id` and `recipient_id` are public identifiers. No message carries an email address,
a name, or any field value.

| `type` | When | Members besides the common ones |
|---|---|---|
| `signing.loaded` | The signing page rendered the agreement for this signer | `envelope_id`, `recipient_id`, `total_fields` (fields this signer completes; may be `0`) |
| `signing.started` | The signer changed their first field on this page load | `envelope_id`, `recipient_id` |
| `signing.completed` | The page confirming this signer's acceptance loaded | `envelope_id`, `recipient_id` |
| `signing.declined` | The page confirming this signer's decline loaded | `envelope_id`, `recipient_id` |
| `signing.error` | A refusal page loaded, or a save failed | `code`; `envelope_id` and `recipient_id` only for `save_failed` |

| `code` | Meaning |
|---|---|
| `link_unusable` | The link cannot be used: expired, already used, replaced, or the agreement is no longer open. Which one is deliberately not said (the refusal page is an oracle otherwise). |
| `no_session` | The page was reached without a live signing session. |
| `rate_limited` | Too many attempts from this client; the page says when to try again. |
| `decline_not_allowed` | A decline was posted on an agreement whose signers may not decline (issue #165). |
| `review_stale` | The agreement changed under the signer; they are asked to read it again before signing. |
| `save_failed` | Saving the signer's entries failed. The page says so and keeps them. |

`signing.completed` and `signing.declined` are announced by the confirmation pages, which are
also what a reload shows, so a reload announces them again. Treat them as idempotent.

## What the announcements are not

They are a convenience for the embedding page's interface, sent by a browser. They are not
evidence of anything and not the durable signal: an acceptance is recorded by the service, and
`signing_request.recipient.signed`, `signing_request.recipient.declined` and the completion
event (`docs/delivery/webhooks.md`) are what a system of record should act on. A
`signing.completed` that a page never received does not mean the signer did not sign.

## Receiving them

```js
const allowed = 'https://sign.example.org'; // this deployment's own origin

window.addEventListener('message', (event) => {
  if (event.origin !== allowed) return;
  const data = event.data;
  if (data?.source !== 'esign-signing' || data.version !== 1) return;

  switch (data.type) {
    case 'signing.completed': /* close the popup, refresh from the API */ break;
    case 'signing.error': /* data.code */ break;
  }
});
```

## Relation to the compatibility profile

Upstream's embedded signing iframe posts `signing.loaded` through `signing.error` with
`source: 'firma-signing'`, camelCase members (`signingUserId`, `signerEmail`, `totalFields`) and
uppercase codes. This is the native contract and keeps this application's vocabulary: snake_case
members, lowercase codes, no email, and an explicit `version`. It is not a facade of upstream's,
because the upstream URL it describes is not one this application serves (capability matrix,
"Base URL / signing links").
