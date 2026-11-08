# UX flows

A design canvas of every screen the product shows today, built from the real Blade and React
markup and copy (`resources/views/`, `resources/js/`) in the app's own font and colours. It is
for reviewing and iterating on the design; nothing here is shipped or loaded by the
application.

| Row | Screens |
|---|---|
| Signer: invitation to signature (phone width) | `Main` (invitation email) → `SignLanding` → `SignCode` → `SignSession` → `SignDecline` → `SignComplete` / `SignDeclined` |
| Signer: interruptions and refusals | `SignStale`, `SignNoSession`, `SignUnavailable`, `SignRateLimited`, `SignDeclineUnavailable`, `SignLegacy` |
| Sender and workspace admin (desktop) | `AdminSignIn`, `AdminDashboard`, `AdminMembers`, `AdminInvitation`, `AdminEditor` |

Review questions, known gaps (there is no sender UI for agreements) and small findings are
sticky notes in `canvas.json` under `notes`.

## Files

- `canvas.json` — the index: one entry per artboard (its frame on the canvas, title, and
  whether it is interactive), the order, and the notes.
- `<Name>.dc.html` — one screen each. Plain HTML with inline styles inside an `<x-dc>` element,
  plus a small `<script type="text/x-dc">` block the canvas editor reads. Screens link to the
  next one with ordinary `<a href="Other.dc.html">`, so the canvas's Play mode walks a flow.

Opened directly in a browser, a file renders as a static mock-up (the `./support.js` it names
is the canvas runtime and is not in this repository, so the browser just skips it).

## Iterating

This folder is the source of truth; the hosted canvas is a working copy.

1. To change a screen, edit its `.dc.html` here (or ask Claude to), keeping the shapes above:
   inline styles, real `<button>`/`<a>`/`<label>` elements, synthetic names and addresses only
   (AGENTS.md).
2. To view or edit it visually, publish the folder to a Design canvas: ask Claude Code to
   "publish docs/ux/flows to the UX flows canvas". Edits made on the canvas are copied back
   here the same way ("pull the UX flows canvas into docs/ux/flows") and committed.
3. When a screen in the app changes, update its artboard in the same pull request, so the
   canvas keeps describing what ships. A screen that is a proposal rather than what exists
   says so on the artboard.
