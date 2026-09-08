import {
  BROWSER_RENDERABLE_IMAGE_MIME_TYPES,
  isBrowserRenderableImage,
} from "@/lib/isBrowserRenderableImage";

describe("isBrowserRenderableImage", () => {
  it.each([...BROWSER_RENDERABLE_IMAGE_MIME_TYPES])("allows %s", (mimeType) => {
    expect(isBrowserRenderableImage(mimeType)).toBe(true);
  });

  it.each(["image/heic", "image/heif", "image/tiff", "image/x-adobe-dng", "image/bmp"])(
    "rejects %s even though it starts with image/",
    (mimeType) => {
      expect(isBrowserRenderableImage(mimeType)).toBe(false);
    },
  );

  it.each(["application/pdf", "text/html", "video/mp4"])("rejects non-image type %s", (mimeType) => {
    expect(isBrowserRenderableImage(mimeType)).toBe(false);
  });

  it("normalizes case", () => {
    expect(isBrowserRenderableImage("IMAGE/PNG")).toBe(true);
    expect(isBrowserRenderableImage("Image/Jpeg")).toBe(true);
    expect(isBrowserRenderableImage("IMAGE/HEIC")).toBe(false);
  });

  it("strips MIME parameters", () => {
    expect(isBrowserRenderableImage("image/png; charset=binary")).toBe(true);
    expect(isBrowserRenderableImage("image/jpeg;charset=utf-8")).toBe(true);
    expect(isBrowserRenderableImage("image/tiff; charset=binary")).toBe(false);
  });

  it("handles surrounding whitespace", () => {
    expect(isBrowserRenderableImage(" image/webp ")).toBe(true);
  });

  it("rejects null, undefined, and empty input", () => {
    expect(isBrowserRenderableImage(null)).toBe(false);
    expect(isBrowserRenderableImage(undefined)).toBe(false);
    expect(isBrowserRenderableImage("")).toBe(false);
  });
});
