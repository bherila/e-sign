/**
 * MIME types that every mainstream browser (Chrome, Firefox, Safari, Edge)
 * can decode in an <img> element.
 *
 * Deliberately an allowlist. Do NOT gate previews on
 * `mimeType.startsWith("image/")`: image/heic and image/heif decode only in
 * Safari, and image/tiff does not decode in Chrome or Firefox — all three
 * render a broken <img> for most users while still passing a prefix check.
 */
export const BROWSER_RENDERABLE_IMAGE_MIME_TYPES = [
  "image/png",
  "image/jpeg",
  "image/gif",
  "image/webp",
  "image/avif",
  "image/svg+xml",
] as const;

/**
 * Whether a stored MIME type is safe to render inline via <img> in all
 * mainstream browsers.
 *
 * Normalizes case and strips parameters (e.g. "image/PNG; charset=binary"
 * matches). Returns false for null/undefined/empty input and for
 * image types outside the allowlist (HEIC, HEIF, TIFF, BMP variants, RAW
 * formats, ...). For those, offer a download link instead of an inline
 * preview.
 */
export function isBrowserRenderableImage(mimeType: string | null | undefined): boolean {
  if (!mimeType) {
    return false;
  }

  const normalized = (mimeType.split(";")[0] ?? "").trim().toLowerCase();

  return (BROWSER_RENDERABLE_IMAGE_MIME_TYPES as readonly string[]).includes(normalized);
}
