# The visual field editor

Where a sender says who signs what, and where. It is a React island on a Blade page that
renders the template version's own PDF revision with PDF.js — served from this application,
never a CDN — and draws the version's field set over it.

Implements issue #22. Read with [field-schema.md](field-schema.md) for the vocabulary,
[coordinate-space.md](coordinate-space.md) for what a coordinate means,
[templates.md](templates.md) for the version lifecycle it saves into, and section 7 of
[docs/HANDOFF.md](../HANDOFF.md) for the requirement.

| | |
|---|---|
| Route | `GET /workspaces/{workspace}/templates/{template}/versions/{version}/editor`, in [`routes/editor.php`](../../routes/editor.php) |
| HTTP | `app/Http/Controllers/Editor/FieldEditorController`, `app/Http/Requests/Editor/ShowFieldEditorRequest`, `resources/views/editor.blade.php` |
| Client | [`resources/js/editor.tsx`](../../resources/js/editor.tsx) and `resources/js/components/editor/` |
| Tests | `resources/js/components/editor/*.test.ts(x)`, `tests/Feature/Preparation/FieldEditorPageTest.php` |

## What it does

- **Pages.** One canvas per page, each at its own size, rasterised by PDF.js at the device
  pixel ratio and released again when it scrolls well out of view. Page navigation by number
  and by previous/next.
- **Zoom.** Fit width, or 50% to 200%. Fit width follows the window and is measured against the
  *widest* page, so a landscape page in a portrait document still fits.
- **Placing.** Drag to move, corner handles to resize, arrow keys to nudge, numeric inputs for
  `x`, `y`, `width`, `height` and `page` in points. A field can also be placed by double
  clicking an empty part of a page.
- **Describing.** Type (the schema's nine and no others), recipient, required, read-only,
  label, template alias, and prefill variable.
- **Recipients.** A fixed colour per recipient with a legend that also states each one's
  signing stage. Colour is never the only signal: every box names its recipient in its
  accessible name, and the table names it in a column.
- **Duplicate and delete**, with undo and redo over the whole session (in memory; a reload
  starts clean).
- **The fields table**, a complete peer of the page view — see [Accessibility](#accessibility).
- **Validation**, continuously, through the same `parseFieldSchema` the API uses.
- **JSON in and out**, canonical, through the same functions.
- **Save**, a `PATCH` to the template version endpoint, with a concurrent-edit check.

## Keyboard map

Everything is reachable by tabbing. These are the shortcuts on top of that.

| Keys | Where | Does |
|---|---|---|
| `Tab` / `Shift+Tab` | everywhere | move through the toolbar, the field boxes, the table and the panels, with a visible focus ring |
| `←` `→` `↑` `↓` | a focused field box | nudge by 1 pt |
| `Shift` + arrow | a focused field box | nudge by 10 pt |
| `Delete` / `Backspace` | a focused field box | delete the field |
| `↑` `↓` | a focused numeric input | the browser's own step (1 pt, or 5% for zoom) |
| `Ctrl/Cmd + Z` | outside a text field | undo |
| `Ctrl/Cmd + Shift + Z`, `Ctrl/Cmd + Y` | outside a text field | redo |
| `Ctrl/Cmd + S` | anywhere | save |

Undo and redo inside a text input are left to the text input, which is what a person pressing
them there means.

Resizing has no key of its own on the page view: the corner handles are `aria-hidden` and not
focusable, because the width and height inputs in the inspector and in the table already do it
exactly, and four extra tab stops per field would make the tab order unusable in a document
with fifty fields.

## How coordinates flow

There is one coordinate space in the product — pt, top-left origin, CropBox, displayed
rotation, 1-based pages — and the editor stores and exchanges nothing else. Screen pixels exist
only between a pointer event and the reducer.

```
preflight report ──▶ pages[] on the page payload ──▶ parsePageGeometry ──▶ PageTransform
                                                                             │
   pointer event (CSS px) ───────────────────────────────────────────────────┤
                                                                             ▼
                                                             screenToNative / screenToRect
                                                                             │
                                                                        pt   ▼
                                                        editorReducer ──▶ document (canonical)
                                                                             │
                       serializeFieldSchema ──▶ PATCH field_schema ──▶ FieldSchemaValidator
                                                                             │
                                                                             ▼
                                            template_versions.field_schema + its sha256
```

Four rules hold it together:

1. **One module converts.** `components/editor/PageTransform.ts` is the only place a pixel
   becomes a point. Nothing else multiplies by the zoom.
2. **Geometry comes from the server.** Page sizes are the displayed sizes in the document's
   preflight report, embedded in the page payload rather than fetched separately, so the editor
   can never open with the PDF drawn and the geometry still in flight. PDF.js's own viewport is
   *fitted to* that size; it does not define it.
3. **State is always canonical.** Every mutation rounds once, to three decimals, with the
   schema's `roundCoordinate`. Serialising the state at any moment produces the bytes the
   server would store, so the round trip is exact rather than within a tolerance.
4. **Nothing is inferred.** `PageTransform` refuses a `/Rotate` that is not a multiple of 90,
   a CropBox without positive extent, and a non-positive zoom, rather than guessing. No number
   is read as a percentage because it happens to be small.

`PageTransform` also carries the native ↔ PDF user-space transform — the rotation table and the
CropBox offset from [coordinate-space.md](coordinate-space.md) — mirroring
`App\Domain\Preparation\Geometry\CoordinateTransform` line for line. The editor does not need it
to place a box; it is there so the browser's idea of the page and the assembler's can be
compared in a test rather than asserted in prose.

## Validation

The panel runs `validateFieldSchema` on every change with the page sizes it was given, and
lists **every** problem at once, each with its JSON Pointer and stable code; selecting one
selects the field it points at. The count is announced through a polite live region.

Two checks depend on context the editor may not have, and an omitted check is never reported as
a passed one:

- **Page geometry** is always supplied, so "the page exists" and "the rectangle fits it" are
  always checked.
- **Prefill variables** are only checked when the deployment declares a list in
  `esign.preparation.prefill_variables` (`ESIGN_PREFILL_VARIABLES`). A template has no sending
  context, which is why `TemplateService` does not run the check either. With the list empty the
  panel says the check was skipped; the send-time gate still runs it. Validating against an
  empty list would report every prefill in the document as unresolvable.

When the server refuses a save, its `field_schema_errors` are shown **verbatim**, message and
code exactly as sent, in their own block below the local list. The server's list is
authoritative — it runs checks the browser cannot — and a friendlier local paraphrase would be a
second error vocabulary that drifts from the API's.

## Saving, and the concurrent-edit check

Save is a `PATCH` to `/workspaces/{workspace}/templates/{template}/versions/{version}` carrying
`field_schema`. There is deliberately **no editor-specific write endpoint**: one place validates
and stores a field set, so there is one definition of what a valid one is.

Before writing, the editor re-reads the version and compares `field_schema_sha256` with the
digest it opened. If it moved, somebody else saved in the meantime and the editor stops and
offers two honest choices — reload theirs, or overwrite with a second, forced save. There is no
`If-Match` on that endpoint, so this is a check-then-write with a narrow race remaining rather
than a lock, and the wording on screen says so.

A published version answers `409 version_published`; the page is already read-only in that case,
and the message is shown as sent.

## Read-only

`read_only` is decided on the server and rendered into the payload, never inferred in the
browser:

| Reason | When |
|---|---|
| `version_published` | the version is published and therefore frozen. An edit after publishing is version n+1 |
| `insufficient_role` | the caller's workspace role does not carry `createTemplates` |

Publication wins when both apply, because it is the reason nobody can resolve by changing a
role. The route itself takes `view`, so an **auditor can open the editor** and read every field
placement and change none of it — which is most of what auditing a field set means. Gating the
page on `createTemplates` would have made the only visual view of a field set invisible to the
role whose job is looking at things.

## PDF.js, served locally

`pdfjs-dist` is a runtime dependency (Apache-2.0). The library is bundled, the worker is a
`?url` import emitted into `public/build`, and the four directories PDF.js fetches by path at
runtime — `cmaps`, `standard_fonts`, `wasm`, `iccs` — are copied to `public/vendor/pdfjs/` by a
plugin in `vite.config.ts`. Every one of those settings is required: unset, PDF.js falls back to
its published CDN, and AGENTS.md forbids a third-party origin on preparation and signing pages.
The details and the licence obligations are in
[`THIRD_PARTY_NOTICES.md`](../../THIRD_PARTY_NOTICES.md).

The editor chunk and PDF.js are both loaded lazily, so no other page in the application pays for
either, and a page that cannot parse its own payload does not download a megabyte of renderer to
say so.

## Accessibility

- Every interactive element is a real control — `button`, `input`, `select`, `checkbox` — takes
  focus in document order, and shows the same visible focus ring as the rest of the application.
- A field box is a `<button>` with an accessible name that states the recipient, the type, the
  label, the page and the rectangle in points. A sighted user reads the position from the page;
  everybody else reads it from the name.
- **The fields table is the accessible path, and it is a peer rather than a fallback.** Every
  property of every field is editable there, in native units, with platform controls: placing a
  signature box with the keyboard alone is tabbing to a row and typing four numbers. Nothing in
  the editor can only be done by dragging.
- Colour is never the only signal. Recipient is in the legend, in each box's accessible name,
  and in a table column; a field with validation errors is marked in text, not only by a dashed
  border.
- Validation counts and import results are announced through polite live regions.
- The picker controls are plain `<select>` elements rather than the portalled listbox in
  `components/ui/select`, because the table has one on every row and the platform control is
  what the browser and the screen reader already know how to drive.

## Out of scope

- **The mobile signing UI is Stage 3.** This is the preparation surface; nothing here decides
  how a signer sees a field on a phone. The editor must not preclude that layout, which is why
  the field set carries only geometry and semantics, no viewport assumptions — but building it
  is a different issue.
- **Recipient management.** Recipients and the signing order come from the version's field set
  and are shown, not edited. Adding a recipient today means editing the JSON or using the
  template version API; a recipient editor is its own piece of work with its own rules about
  what happens to fields bound to somebody who is removed.
- **Anchor authoring.** An anchor on an imported field is displayed and preserved; the editor
  does not create one. Anchor resolution is deterministic positioned-text extraction on the
  server (`app/Domain/Preparation/Text`), and an authoring UI for it needs the extracted text
  runs, which this page does not have.
- **Publishing.** The editor saves a draft. Publishing is a separate, deliberate action on the
  template version endpoint.
- **Undo across reloads.** History is in memory. A saved version is the durable record, and the
  version list is the durable history.
