/**
 * Where PDF.js finds the resources it fetches by path at runtime.
 *
 * Imported by `vite.config.ts`, which copies `cmaps/`, `standard_fonts/`, `wasm/` and `iccs/`
 * out of `node_modules/pdfjs-dist` to exactly this path under `public/`, and by `pdfjs.ts`,
 * which hands the URLs to PDF.js. One constant, so the copy and the fetch cannot drift apart
 * and land on pdfjs' CDN defaults — which this application must never use (AGENTS.md: signing
 * and preparation pages load nothing from a third-party origin).
 */

/** Path under `public/`, without leading or trailing slashes. */
export const PDFJS_PUBLIC_PATH = "vendor/pdfjs";

/** Root-relative base URL, with the trailing slash PDF.js expects on each of its options. */
export const PDFJS_ASSET_BASE = `/${PDFJS_PUBLIC_PATH}/`;
