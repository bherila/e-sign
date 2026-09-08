import './bootstrap';

import { lazy, StrictMode, Suspense } from 'react';
import { createRoot } from 'react-dom/client';

import type { EditorPayload } from '@/components/editor/types';

/**
 * Entry point for the visual field editor page.
 *
 * The editor itself is loaded lazily and PDF.js is loaded lazily inside it, so this entry is a
 * few kilobytes: a page that fails to parse its own payload never downloads a megabyte of PDF
 * renderer to say so, and no other page in the application pays for either.
 *
 * The payload is read from one `data-*` attribute, in the pattern `resources/js/dashboard.tsx`
 * establishes. A malformed attribute degrades to a message rather than a blank page — but it
 * does not degrade to an *empty document*, because an editor that silently opened with no
 * fields would invite somebody to save that over a real field set.
 */
const FieldEditor = lazy(() => import('@/components/editor/FieldEditor'));

function parsePayload(raw: string | null): EditorPayload | null {
  if (!raw) {
    return null;
  }

  try {
    const parsed: unknown = JSON.parse(raw);

    return typeof parsed === 'object' && parsed !== null ? (parsed as EditorPayload) : null;
  } catch {
    return null;
  }
}

const mount = document.getElementById('field-editor');

if (mount) {
  const payload = parsePayload(mount.getAttribute('data-editor'));

  createRoot(mount).render(
    <StrictMode>
      {payload === null ? (
        <p className="text-destructive text-sm">
          This page did not receive a readable editor payload, so nothing was opened and nothing
          can be saved. Reload the page; if it happens again, the field set is still readable
          through the template version API.
        </p>
      ) : (
        <Suspense fallback={<p className="text-muted-foreground text-sm">Loading the field editor…</p>}>
          <FieldEditor payload={payload} />
        </Suspense>
      )}
    </StrictMode>,
  );
}
