/**
 * Vite's asset-import query suffixes, declared for `tsc --noEmit`.
 *
 * `vite/client` would supply these, but `tsconfig.json` sets `"types": []` deliberately — the
 * frontend has no ambient Node or DOM-library assumptions beyond the ones it states — so the
 * one suffix this codebase uses is declared here instead of pulling in a whole ambient
 * package. `?url` resolves to the emitted asset's URL, which is how the PDF.js worker is
 * served from this origin rather than from a CDN.
 */
declare module "*?url" {
  const url: string;
  export default url;
}
