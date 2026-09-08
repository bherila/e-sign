/**
 * PDF.js, served entirely from this origin.
 *
 * Three things are load-bearing here:
 *
 * 1. **No CDN, at all.** `pdfjs-dist` is a normal runtime dependency; the worker is a `?url`
 *    import, so Vite emits it into `public/build` and the browser fetches it from this host;
 *    and the four runtime resource directories are copied to `public/vendor/pdfjs` by the
 *    plugin in `vite.config.ts`. Leaving any of these unset makes PDF.js fall back to its
 *    published CDN, which AGENTS.md forbids on preparation and signing pages.
 * 2. **The worker is real.** Running PDF.js on the main thread ("fake worker") parses a
 *    multi-megabyte PDF on the UI thread and makes dragging a field stutter, and it is what
 *    happens silently when `workerSrc` is wrong. It is set here, once.
 * 3. **The raster is fitted to the server's geometry, never the other way round.** Field
 *    rectangles are validated against the displayed page size in the preflight report, so
 *    that report — not the viewport PDF.js computes — decides how big a page is. `renderPage`
 *    takes the CSS size the caller derived from `PageTransform` and scales the viewport to
 *    fill it exactly. If the two ever disagreed, the overlay would still line up with the
 *    coordinates the document is stored in.
 *
 * This module is imported dynamically (`await import("./pdfjs")`), so the ~1 MB of PDF.js
 * stays out of the editor's first chunk and off every other page in the application.
 */

import * as pdfjs from "pdfjs-dist";
import workerUrl from "pdfjs-dist/build/pdf.worker.min.mjs?url";

import { PDFJS_ASSET_BASE } from "./pdfjsAssets";

pdfjs.GlobalWorkerOptions.workerSrc = workerUrl;

export interface LoadedPdf {
  pageCount: number;
  /**
   * Draw one page into a canvas at the given CSS size and device pixel ratio.
   *
   * Resolves when the page is on the canvas. Rejects with a `RenderingCancelledException` if
   * a later call for the same page superseded it, which the caller treats as "not an error".
   */
  renderPage(
    pageNumber: number,
    canvas: HTMLCanvasElement,
    cssWidth: number,
    cssHeight: number,
    devicePixelRatio: number,
  ): Promise<void>;
  destroy(): Promise<void>;
}

/** True when a rejection is PDF.js cancelling a superseded render rather than a failure. */
export function isRenderCancellation(error: unknown): boolean {
  return error instanceof Error && error.name === "RenderingCancelledException";
}

export async function loadPdf(url: string): Promise<LoadedPdf> {
  const loadingTask = pdfjs.getDocument({
    url,
    // Same-origin, but the document endpoint is session-authenticated and streams through the
    // application; stating this keeps the request carrying the session cookie whichever
    // transport PDF.js picks.
    withCredentials: true,
    // Everything below is a local path. Unset, each of these falls back to a CDN URL.
    cMapUrl: `${PDFJS_ASSET_BASE}cmaps/`,
    cMapPacked: true,
    standardFontDataUrl: `${PDFJS_ASSET_BASE}standard_fonts/`,
    wasmUrl: `${PDFJS_ASSET_BASE}wasm/`,
    iccUrl: `${PDFJS_ASSET_BASE}iccs/`,
  });

  const pdf = await loadingTask.promise;
  const renders = new Map<number, { cancel(): void }>();

  return {
    pageCount: pdf.numPages,

    async renderPage(pageNumber, canvas, cssWidth, cssHeight, devicePixelRatio): Promise<void> {
      renders.get(pageNumber)?.cancel();

      const page = await pdf.getPage(pageNumber);
      const unscaled = page.getViewport({ scale: 1 });

      // The scale that makes the raster exactly fill the box the coordinate space says the
      // page occupies. Derived, not assumed equal to the editor's zoom.
      const scale = (cssWidth * devicePixelRatio) / unscaled.width;
      const viewport = page.getViewport({ scale });

      canvas.width = Math.max(1, Math.round(cssWidth * devicePixelRatio));
      canvas.height = Math.max(1, Math.round(cssHeight * devicePixelRatio));
      canvas.style.width = `${cssWidth}px`;
      canvas.style.height = `${cssHeight}px`;

      // `canvas` rather than `canvasContext`: PDF.js 6 documents the context parameter as
      // backwards compatibility only, and passing both is contradictory — it requires the
      // canvas to be null when the context is the one that must be used.
      const task = page.render({ canvas, viewport });
      renders.set(pageNumber, task);

      try {
        await task.promise;
      } finally {
        if (renders.get(pageNumber) === task) {
          renders.delete(pageNumber);
        }
      }
    },

    async destroy(): Promise<void> {
      for (const render of renders.values()) {
        render.cancel();
      }

      renders.clear();
      // The loading task owns the worker; destroying it tears down the document and the
      // worker together, which `PDFDocumentProxy` alone does not.
      await loadingTask.destroy();
    },
  };
}
