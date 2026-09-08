/**
 * The one place screen pixels become points and points become screen pixels.
 *
 * Every coordinate in the editor is converted here and nowhere else. A drag, a resize, a
 * numeric input, a rendered overlay and a validation message all go through this module, so
 * there is exactly one definition of what a point is on screen and one place to be wrong.
 * Nothing anywhere else multiplies by a zoom level.
 *
 * Native space is the product's only coordinate space, defined normatively in
 * `docs/preparation/coordinate-space.md` and implemented on the server by
 * `App\Domain\Preparation\Geometry\CoordinateTransform`:
 *
 * | | |
 * |---|---|
 * | Unit | `pt`, one PDF default user-space unit |
 * | Origin | top-left corner of the **displayed** page |
 * | Axes | x right, y **down** |
 * | Page box | `/CropBox`, already clipped to `/MediaBox` |
 * | Rotation | as displayed: `/Rotate` is already applied |
 * | Page index | 1-based |
 *
 * Two conversions live here, and they are different problems:
 *
 * 1. **Native <-> screen.** A pure scale, because the raster underneath is a PDF.js viewport
 *    built with the page's own rotation, whose top-left pixel *is* native (0, 0). Rotation
 *    enters only through the displayed page size. This is the conversion a drag uses.
 * 2. **Native <-> PDF user space.** The rotation table and the CropBox offset, transcribed
 *    from `coordinate-space.md` and matching `CoordinateTransform` line for line. The editor
 *    does not need it to place a box, but it is what makes "preview geometry matches final
 *    assembly geometry" checkable on this side rather than asserted, and it is what a
 *    reviewer compares against the PHP.
 *
 * Units are never inferred. A number that arrives without a declared space is not accepted,
 * and nothing here decides that a small value "must be" a percentage.
 */

import type { PageSize, Rect } from "@/schema/fieldSchema";

/** `/Rotate`, normalised. Anything else is an error rather than a rounding. */
export const PAGE_ROTATIONS = [0, 90, 180, 270] as const;

export type PageRotation = (typeof PAGE_ROTATIONS)[number];

/** An effective `/CropBox`, in PDF user space, already clipped to the `/MediaBox`. */
export interface CropBox {
  x0: number;
  y0: number;
  x1: number;
  y1: number;
}

/** Everything the coordinate space needs to know about one page. Mirrors `PageGeometry` in PHP. */
export interface PageGeometry {
  /** 1-based. */
  page: number;
  cropBox: CropBox;
  rotation: PageRotation;
  /** Recorded, never applied implicitly. See the `/UserUnit` section of coordinate-space.md. */
  userUnit: number;
}

/** A point in native space: pt, top-left origin, y downwards, rotation applied. */
export interface NativePoint {
  x: number;
  y: number;
}

/** A point in PDF default user space: origin bottom-left of the MediaBox, y upwards. */
export interface UserSpacePoint {
  x: number;
  y: number;
}

/** A point in CSS pixels, measured from the top-left corner of the rendered page element. */
export interface ScreenPoint {
  left: number;
  top: number;
}

/** A rectangle in CSS pixels, ready to become `style.left/top/width/height`. */
export interface ScreenRect {
  left: number;
  top: number;
  width: number;
  height: number;
}

/** The shape the server writes into the page's `data-editor` attribute. */
export interface PageGeometryPayload {
  page: number;
  crop_box: number[];
  rotation: number;
  user_unit: number;
  native_width: number;
  native_height: number;
}

export class InvalidPageGeometryError extends Error {
  constructor(message: string) {
    super(message);
    this.name = "InvalidPageGeometryError";
  }
}

export function isPageRotation(value: number): value is PageRotation {
  return (PAGE_ROTATIONS as readonly number[]).includes(value);
}

/**
 * Normalise a `/Rotate` value the way the PDF specification requires: negative and
 * above-360 multiples of 90 are legal and mean what they say. Anything that is not a
 * multiple of 90 is an error, never rounded to the nearest quarter turn.
 */
export function normaliseRotation(rotate: number): PageRotation {
  if (!Number.isFinite(rotate) || rotate % 90 !== 0) {
    throw new InvalidPageGeometryError(`/Rotate must be a multiple of 90; got ${rotate}.`);
  }

  const normalised = ((rotate % 360) + 360) % 360;

  if (!isPageRotation(normalised)) {
    // Unreachable given the modulo above; present so a future edit cannot widen the type.
    throw new InvalidPageGeometryError(`/Rotate normalised to an unsupported value: ${normalised}.`);
  }

  return normalised;
}

/**
 * Read one page's geometry out of the server payload.
 *
 * Fails loudly. A page the editor cannot describe is a page on which it must not let anybody
 * place a field, and a default CropBox invented here would put every field on that page in
 * the wrong place.
 */
export function parsePageGeometry(payload: PageGeometryPayload): PageGeometry {
  const box = payload.crop_box;

  if (box.length !== 4 || box.some((value) => !Number.isFinite(value))) {
    throw new InvalidPageGeometryError(
      `Page ${payload.page} has a CropBox that is not four finite numbers.`,
    );
  }

  const [x0, y0, x1, y1] = box as [number, number, number, number];

  if (x1 <= x0 || y1 <= y0) {
    throw new InvalidPageGeometryError(`Page ${payload.page} has a CropBox with no positive extent.`);
  }

  const userUnit = payload.user_unit;

  if (!Number.isFinite(userUnit) || userUnit <= 0) {
    throw new InvalidPageGeometryError(`Page ${payload.page} has a non-positive /UserUnit.`);
  }

  return {
    page: payload.page,
    cropBox: { x0, y0, x1, y1 },
    rotation: normaliseRotation(payload.rotation),
    userUnit,
  };
}

/**
 * The displayed size of every page, page 1 first, as the schema validator wants it.
 *
 * Derived from the same geometry the editor draws with, so "the rectangle fits the page" means
 * the same thing in the validation panel and on screen.
 */
export function pageSizesOf(geometries: PageGeometry[]): PageSize[] {
  return geometries.map((geometry) => {
    const transform = new PageTransform(geometry, 1);

    return { width: transform.nativeWidth, height: transform.nativeHeight };
  });
}

/**
 * One page at one zoom level.
 *
 * Immutable and cheap: a new zoom is a new instance, never a mutated one, so a stale
 * transform cannot convert a drag at last frame's scale.
 */
export class PageTransform {
  public readonly geometry: PageGeometry;

  /** CSS pixels per native point. 1 means "one point is one CSS pixel", i.e. 100%. */
  public readonly scale: number;

  constructor(geometry: PageGeometry, scale: number) {
    if (!Number.isFinite(scale) || scale <= 0) {
      throw new InvalidPageGeometryError(`Zoom scale must be a positive finite number; got ${scale}.`);
    }

    this.geometry = geometry;
    this.scale = scale;
  }

  /** Width of the CropBox in user space, before rotation. */
  get cropWidth(): number {
    return this.geometry.cropBox.x1 - this.geometry.cropBox.x0;
  }

  /** Height of the CropBox in user space, before rotation. */
  get cropHeight(): number {
    return this.geometry.cropBox.y1 - this.geometry.cropBox.y0;
  }

  /** True for a quarter turn, where the displayed width and height are the CropBox's swapped. */
  get swapsAxes(): boolean {
    return this.geometry.rotation === 90 || this.geometry.rotation === 270;
  }

  /** Displayed page width in native points. */
  get nativeWidth(): number {
    return this.swapsAxes ? this.cropHeight : this.cropWidth;
  }

  /** Displayed page height in native points. */
  get nativeHeight(): number {
    return this.swapsAxes ? this.cropWidth : this.cropHeight;
  }

  /** Displayed page size in CSS pixels at this zoom. */
  get cssWidth(): number {
    return this.nativeWidth * this.scale;
  }

  get cssHeight(): number {
    return this.nativeHeight * this.scale;
  }

  /** The whole page as a native rectangle, for clamping and containment. */
  get pageRect(): Rect {
    return { x: 0, y: 0, width: this.nativeWidth, height: this.nativeHeight };
  }

  /** Same page, different zoom. */
  withScale(scale: number): PageTransform {
    return new PageTransform(this.geometry, scale);
  }

  /**
   * The zoom at which the displayed page exactly fills `availableCssWidth`.
   *
   * Not clamped here: the caller decides what its zoom limits are, and silently returning a
   * different scale than the one asked for is how a "fit width" button stops fitting.
   */
  fitWidthScale(availableCssWidth: number): number {
    if (!Number.isFinite(availableCssWidth) || availableCssWidth <= 0) {
      throw new InvalidPageGeometryError(
        `Available width must be a positive finite number; got ${availableCssWidth}.`,
      );
    }

    return availableCssWidth / this.nativeWidth;
  }

  /**
   * Backing-store size for a canvas at this zoom on a display with this device pixel ratio.
   *
   * Rounded, because a canvas cannot have a fractional backing store; the CSS size stays
   * fractional so the layout keeps agreeing with the coordinates.
   */
  canvasWidth(devicePixelRatio: number): number {
    return Math.max(1, Math.round(this.cssWidth * devicePixelRatio));
  }

  canvasHeight(devicePixelRatio: number): number {
    return Math.max(1, Math.round(this.cssHeight * devicePixelRatio));
  }

  // ------------------------------------------------------------------ native <-> screen

  nativeToScreen(point: NativePoint): ScreenPoint {
    return { left: point.x * this.scale, top: point.y * this.scale };
  }

  screenToNative(point: ScreenPoint): NativePoint {
    return { x: point.left / this.scale, y: point.top / this.scale };
  }

  /** A field rectangle as CSS pixels on the rendered page. */
  rectToScreen(rect: Rect): ScreenRect {
    return {
      left: rect.x * this.scale,
      top: rect.y * this.scale,
      width: rect.width * this.scale,
      height: rect.height * this.scale,
    };
  }

  /** A rectangle dragged out on screen, back in points. */
  screenToRect(rect: ScreenRect): Rect {
    return {
      x: rect.left / this.scale,
      y: rect.top / this.scale,
      width: rect.width / this.scale,
      height: rect.height / this.scale,
    };
  }

  /** A screen distance in points. Used by drag deltas, which have no origin. */
  screenLengthToNative(length: number): number {
    return length / this.scale;
  }

  nativeLengthToScreen(length: number): number {
    return length * this.scale;
  }

  // -------------------------------------------------------------- native <-> user space

  /**
   * Native point -> PDF default user space.
   *
   * Two steps, transcribed from `docs/preparation/coordinate-space.md` and from
   * `CoordinateTransform::toUserSpace()`: undo the display rotation to get unrotated page
   * coordinates measured from the top-left of the CropBox, then flip y and add the box origin.
   */
  toUserSpace(point: NativePoint): UserSpacePoint {
    const { cropBox, rotation } = this.geometry;
    const cw = this.cropWidth;
    const ch = this.cropHeight;

    let px: number;
    let py: number;

    switch (rotation) {
      case 90:
        px = point.y;
        py = ch - point.x;
        break;
      case 180:
        px = cw - point.x;
        py = ch - point.y;
        break;
      case 270:
        px = cw - point.y;
        py = point.x;
        break;
      default:
        px = point.x;
        py = point.y;
        break;
    }

    return { x: cropBox.x0 + px, y: cropBox.y0 + (ch - py) };
  }

  /** PDF user space -> native point. The exact inverse of {@link toUserSpace}. */
  toNative(point: UserSpacePoint): NativePoint {
    const { cropBox, rotation } = this.geometry;
    const cw = this.cropWidth;
    const ch = this.cropHeight;

    const px = point.x - cropBox.x0;
    const py = ch - (point.y - cropBox.y0);

    switch (rotation) {
      case 90:
        return { x: ch - py, y: px };
      case 180:
        return { x: cw - px, y: ch - py };
      case 270:
        return { x: py, y: cw - px };
      default:
        return { x: px, y: py };
    }
  }

  /**
   * Native length -> physical points (1/72 inch on paper).
   *
   * Declared, never implicit: `/UserUnit` scales the physical size of a unit and does not
   * rescale a stored rectangle. Nothing in the drag path calls this.
   */
  nativeToPhysicalPoints(length: number): number {
    return length * this.geometry.userUnit;
  }

  // ------------------------------------------------------------------------- containment

  /** True when the rectangle lies entirely inside the displayed page. */
  containsRect(rect: Rect, tolerance = 1e-6): boolean {
    return (
      rect.x >= -tolerance &&
      rect.y >= -tolerance &&
      rect.x + rect.width <= this.nativeWidth + tolerance &&
      rect.y + rect.height <= this.nativeHeight + tolerance
    );
  }

  /**
   * Move a rectangle back inside the page without changing its size.
   *
   * A rectangle wider or taller than the page keeps its size and is pinned to the top-left:
   * shrinking it would silently change a dimension the sender typed, and the validator's
   * `rect_out_of_page` then says so instead.
   */
  clampRect(rect: Rect): Rect {
    const maxX = Math.max(0, this.nativeWidth - rect.width);
    const maxY = Math.max(0, this.nativeHeight - rect.height);

    return {
      x: Math.min(Math.max(rect.x, 0), maxX),
      y: Math.min(Math.max(rect.y, 0), maxY),
      width: rect.width,
      height: rect.height,
    };
  }
}
