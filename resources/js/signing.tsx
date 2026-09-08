import './bootstrap';

import { lazy, StrictMode, Suspense } from 'react';
import { createRoot } from 'react-dom/client';

import type { SigningPayload } from '@/signing/types';

/**
 * Entry point for the guest signing page.
 *
 * The island is loaded lazily and PDF.js is loaded lazily inside it, so this entry is a few
 * kilobytes: a page that cannot parse its own payload never downloads a megabyte of renderer
 * to say so, and no other page in the application pays for either.
 *
 * A malformed payload degrades to a message rather than a blank page — and specifically not
 * to an empty agreement. A signing page that opened with no document and no fields would
 * invite somebody to agree to nothing, which is the one failure this whole module exists to
 * prevent.
 */
const SigningPage = lazy(() =>
  import('@/signing/SigningPage').then((module) => ({ default: module.SigningPage })),
);

function parsePayload(raw: string | null): SigningPayload | null {
  if (!raw) {
    return null;
  }

  try {
    const parsed: unknown = JSON.parse(raw);

    return typeof parsed === 'object' && parsed !== null ? (parsed as SigningPayload) : null;
  } catch {
    return null;
  }
}

const mount = document.getElementById('signing-page');

if (mount) {
  const payload = parsePayload(mount.getAttribute('data-signing'));

  createRoot(mount).render(
    <StrictMode>
      {payload === null ? (
        <p className="text-destructive text-sm">
          This page did not receive a readable agreement, so nothing was opened and nothing can
          be signed. Reload it; if it happens again, tell whoever sent you the link.
        </p>
      ) : (
        <Suspense
          fallback={<p className="text-muted-foreground text-sm">Loading the agreement…</p>}
        >
          <SigningPage payload={payload} />
        </Suspense>
      )}
    </StrictMode>,
  );
}
