# `firma-compat-v1` response fixtures

Sanitized responses recorded on 2026-09-08 from the consumer's **staging** Firma workspace with
its API key, using read-only `GET` calls only (no create, send, patch, or cancel), against the
default base URL `https://api.firma.dev/functions/v1/signing-request-api`. One signing request per
workflow the consumer runs, chosen as the most recent row holding a Firma request id:

| Directory | Consumer workflow | State captured | Recipients | Fields | Field types |
|---|---|---|---|---|---|
| `platform-nda/` | platform NDA (user agreement) | `sent`, not finished; `/download` returns `in_progress` with `is_partial: true` | 2 | 12 | date, signature, text |
| `practice-nda/` | sequential buyer/seller practice NDA | `sent` + `finished`; certificate generated; `/download` `finished` | 2 | 6 | signature, text |
| `order-form/` | order form | `sent` + `cancelled`; `/download` `cancelled`, `is_partial: true` | 2 | 2 | signature |
| `data-destruction/` | data-destruction certification | `sent` + `finished`; certificate generated | 1 | 7 | date, signature, text |

Each directory holds the four endpoints the consumer polls: `request.json`
(`GET /signing-requests/{id}`), `users.json` (`/users`), `fields.json` (`/fields`), `download.json`
(`/download`). The create, create-and-send, patch, send, and cancel request/response shapes are
**not** here; they are derived from the consumer's client code and the pinned reference (see
`../SCHEMA.md`) and stay marked "unverified against live" in the capability matrix.

## Sanitization

Applied by a one-off script before anything entered this repository; the raw responses were
deleted. Structure, key order, key names (including upstream typos `x_postion` and `heigh`),
booleans, numbers, coordinates, timestamps, field types, variable names, format rules, and
enum-like strings are unchanged. Replaced:

- every UUID with a deterministic synthetic UUID (same original id maps to the same synthetic id
  across the four files of a workflow, so cross-references still resolve);
- every URL with `https://files.example.test/<kind>/<uuid>?token=REDACTED`;
- recipient names, emails, addresses, titles, companies, phone numbers with `Recipient N` /
  `recipient-N@example.test` style values;
- request names with `Synthetic <workflow> agreement`;
- typed field values with `Synthetic <type> value`, signature images with a 1x1 PNG data URL,
  and signatures rendered as text with `Recipient Signature`; date-formatted values kept.

A leak check confirmed no email, URL, original UUID, or non-enum string from the raw responses
survives in these files.

## Things the live responses showed that the documentation did not

- The polling representation uses **boolean status flags** (`status.sent/finished/cancelled/declined/expired`)
  plus a `timestamps` object, exactly as the consumer's polling code expects.
- `/download` on an unfinished or cancelled request returns HTTP 200 with `status`
  `in_progress`/`cancelled`, `is_partial: true`, and a **document-only** `download_url`; it is not
  an error.
- Fields carry both the legacy flat coordinates (`x_postion`, `y_position`, `width`, `heigh`) and a
  nested `position {x,y,width,height}`; both are populated with the same numbers.
- Recipient rows carry `finished_on` at the recipient level, and `required_fields` lists identity
  attributes (`first_name`, `email`, …), not document fields.
- One request id stored by the consumer with an `sr_` prefix returns 404 from every endpoint; it
  was recorded by a staging test path and is not a real Firma id. The facade must return the same
  404 shape for unknown ids.
