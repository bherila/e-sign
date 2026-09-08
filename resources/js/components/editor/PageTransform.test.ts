import {
  InvalidPageGeometryError,
  normaliseRotation,
  PAGE_ROTATIONS,
  type PageGeometry,
  type PageRotation,
  pageSizesOf,
  PageTransform,
  parsePageGeometry,
} from "./PageTransform";

/**
 * The coordinate module is the one place a point becomes a pixel, so it is the one place a
 * drift becomes a signature in the wrong place. Everything here is hand-computed from
 * docs/preparation/coordinate-space.md, not from the implementation, and the round-trip
 * tolerance is the schema's own canonical precision: 0.001 pt, roughly a third of a micron.
 */

/** Tighter than the schema's 0.001 pt, so a regression shows up long before it is storable. */
const TOLERANCE = 1e-9;

/** The declared drift budget from the issue's acceptance criteria. */
const SCHEMA_TOLERANCE = 0.001;

/** Letter, no CropBox offset. */
const LETTER = { x0: 0, y0: 0, x1: 612, y1: 792 };

/** A CropBox that does not start at the origin — trim marks on a larger MediaBox. */
const OFFSET_CROP = { x0: 36, y0: 54, x1: 648, y1: 846 };

const ZOOMS = [0.5, 0.75, 1, 1.33, 1.5, 2];

function geometry(rotation: PageRotation, cropBox = LETTER, userUnit = 1): PageGeometry {
  return { page: 1, cropBox, rotation, userUnit };
}

describe("displayed page size", () => {
  it("keeps the CropBox extent upright and swaps it on a quarter turn", () => {
    expect(new PageTransform(geometry(0), 1).nativeWidth).toBe(612);
    expect(new PageTransform(geometry(0), 1).nativeHeight).toBe(792);
    expect(new PageTransform(geometry(180), 1).nativeWidth).toBe(612);
    expect(new PageTransform(geometry(180), 1).nativeHeight).toBe(792);
    expect(new PageTransform(geometry(90), 1).nativeWidth).toBe(792);
    expect(new PageTransform(geometry(90), 1).nativeHeight).toBe(612);
    expect(new PageTransform(geometry(270), 1).nativeWidth).toBe(792);
    expect(new PageTransform(geometry(270), 1).nativeHeight).toBe(612);
  });

  it("measures the offset CropBox, not the MediaBox it sits in", () => {
    const transform = new PageTransform(geometry(0, OFFSET_CROP), 1);

    expect(transform.nativeWidth).toBe(612);
    expect(transform.nativeHeight).toBe(792);
  });

  it("reports the displayed size of every page for the schema validator", () => {
    expect(pageSizesOf([geometry(0), geometry(90)])).toEqual([
      { width: 612, height: 792 },
      { width: 792, height: 612 },
    ]);
  });
});

describe("native <-> screen", () => {
  it("scales a point by the zoom and nothing else", () => {
    const transform = new PageTransform(geometry(0), 1.5);

    expect(transform.nativeToScreen({ x: 60, y: 650 })).toEqual({ left: 90, top: 975 });
    expect(transform.rectToScreen({ x: 60, y: 650, width: 170, height: 36 })).toEqual({
      left: 90,
      top: 975,
      width: 255,
      height: 54,
    });
  });

  it.each(ZOOMS)("round trips a point at zoom %p on every rotation without drift", (zoom) => {
    for (const rotation of PAGE_ROTATIONS) {
      for (const cropBox of [LETTER, OFFSET_CROP]) {
        const transform = new PageTransform(geometry(rotation, cropBox), zoom);

        for (const point of [
          { x: 0, y: 0 },
          { x: 60, y: 650 },
          { x: 505.25, y: 735.5 },
          { x: 411.007, y: 12.125 },
          { x: transform.nativeWidth, y: transform.nativeHeight },
        ]) {
          const back = transform.screenToNative(transform.nativeToScreen(point));

          expect(Math.abs(back.x - point.x)).toBeLessThan(TOLERANCE);
          expect(Math.abs(back.y - point.y)).toBeLessThan(TOLERANCE);
          expect(Math.abs(back.x - point.x)).toBeLessThan(SCHEMA_TOLERANCE);
          expect(Math.abs(back.y - point.y)).toBeLessThan(SCHEMA_TOLERANCE);
        }
      }
    }
  });

  it.each(ZOOMS)("round trips a rectangle at zoom %p on every rotation", (zoom) => {
    const rect = { x: 60.125, y: 649.875, width: 170.5, height: 36.25 };

    for (const rotation of PAGE_ROTATIONS) {
      const transform = new PageTransform(geometry(rotation, OFFSET_CROP), zoom);
      const back = transform.screenToRect(transform.rectToScreen(rect));

      expect(Math.abs(back.x - rect.x)).toBeLessThan(SCHEMA_TOLERANCE);
      expect(Math.abs(back.y - rect.y)).toBeLessThan(SCHEMA_TOLERANCE);
      expect(Math.abs(back.width - rect.width)).toBeLessThan(SCHEMA_TOLERANCE);
      expect(Math.abs(back.height - rect.height)).toBeLessThan(SCHEMA_TOLERANCE);
    }
  });

  it("converts a drag distance without needing an origin", () => {
    const transform = new PageTransform(geometry(0), 2);

    expect(transform.screenLengthToNative(50)).toBe(25);
    expect(transform.nativeLengthToScreen(25)).toBe(50);
  });
});

describe("native <-> PDF user space", () => {
  /*
   * The corner check from coordinate-space.md: native (0, 0) is the displayed top-left, which
   * on a rotated page is a different stored corner of the CropBox. These four expectations are
   * the same ones tests/Unit/Preparation/Geometry/CoordinateTransformTest.php asserts in PHP.
   */
  it("maps the displayed top-left onto the stored corner the rotation implies", () => {
    const crop = OFFSET_CROP;

    expect(new PageTransform(geometry(0, crop), 1).toUserSpace({ x: 0, y: 0 })).toEqual({
      x: crop.x0,
      y: crop.y1,
    });
    expect(new PageTransform(geometry(90, crop), 1).toUserSpace({ x: 0, y: 0 })).toEqual({
      x: crop.x0,
      y: crop.y0,
    });
    expect(new PageTransform(geometry(180, crop), 1).toUserSpace({ x: 0, y: 0 })).toEqual({
      x: crop.x1,
      y: crop.y0,
    });
    expect(new PageTransform(geometry(270, crop), 1).toUserSpace({ x: 0, y: 0 })).toEqual({
      x: crop.x1,
      y: crop.y1,
    });
  });

  it("is its own inverse on every rotation and both boxes", () => {
    for (const rotation of PAGE_ROTATIONS) {
      for (const cropBox of [LETTER, OFFSET_CROP]) {
        const transform = new PageTransform(geometry(rotation, cropBox), 1);

        for (const point of [
          { x: 0, y: 0 },
          { x: 60, y: 650 },
          { x: 411.007, y: 12.125 },
          { x: transform.nativeWidth, y: transform.nativeHeight },
        ]) {
          const back = transform.toNative(transform.toUserSpace(point));

          expect(Math.abs(back.x - point.x)).toBeLessThan(SCHEMA_TOLERANCE);
          expect(Math.abs(back.y - point.y)).toBeLessThan(SCHEMA_TOLERANCE);
        }
      }
    }
  });

  it("never rescales a coordinate by /UserUnit", () => {
    const transform = new PageTransform(geometry(0, LETTER, 2), 1);

    expect(transform.toUserSpace({ x: 60, y: 100 })).toEqual({ x: 60, y: 692 });
    // Physical size is available only when it is asked for by name.
    expect(transform.nativeToPhysicalPoints(60)).toBe(120);
  });
});

describe("zoom, containment and clamping", () => {
  it("fits the displayed width, quarter turns included", () => {
    expect(new PageTransform(geometry(0), 1).fitWidthScale(918)).toBe(1.5);
    expect(new PageTransform(geometry(90), 1).fitWidthScale(792)).toBe(1);
  });

  it("sizes a canvas backing store at the device pixel ratio", () => {
    const transform = new PageTransform(geometry(0), 1.5);

    expect(transform.cssWidth).toBe(918);
    expect(transform.canvasWidth(2)).toBe(1836);
    expect(transform.canvasHeight(2)).toBe(2376);
  });

  it("rejects a non-positive zoom rather than dividing by it later", () => {
    expect(() => new PageTransform(geometry(0), 0)).toThrow(InvalidPageGeometryError);
    expect(() => new PageTransform(geometry(0), Number.NaN)).toThrow(InvalidPageGeometryError);
  });

  it("knows whether a rectangle is on the page", () => {
    const transform = new PageTransform(geometry(0), 1);

    expect(transform.containsRect({ x: 60, y: 650, width: 170, height: 36 })).toBe(true);
    expect(transform.containsRect({ x: 500, y: 650, width: 170, height: 36 })).toBe(false);
    expect(transform.containsRect({ x: -1, y: 650, width: 170, height: 36 })).toBe(false);
  });

  it("moves a rectangle back on the page without changing its size", () => {
    const transform = new PageTransform(geometry(0), 1);

    expect(transform.clampRect({ x: -20, y: -20, width: 170, height: 36 })).toEqual({
      x: 0,
      y: 0,
      width: 170,
      height: 36,
    });
    expect(transform.clampRect({ x: 600, y: 900, width: 170, height: 36 })).toEqual({
      x: 442,
      y: 756,
      width: 170,
      height: 36,
    });
  });

  it("keeps an oversized rectangle oversized rather than silently shrinking it", () => {
    const transform = new PageTransform(geometry(0), 1);

    expect(transform.clampRect({ x: 10, y: 10, width: 700, height: 36 })).toEqual({
      x: 0,
      y: 10,
      width: 700,
      height: 36,
    });
  });
});

describe("reading the server's geometry", () => {
  it("accepts the payload shape the preflight report produces", () => {
    expect(
      parsePageGeometry({
        page: 2,
        crop_box: [36, 54, 648, 846],
        rotation: 90,
        user_unit: 1,
        native_width: 792,
        native_height: 612,
      }),
    ).toEqual({ page: 2, cropBox: OFFSET_CROP, rotation: 90, userUnit: 1 });
  });

  it("normalises legal /Rotate values and refuses illegal ones", () => {
    expect(normaliseRotation(-90)).toBe(270);
    expect(normaliseRotation(450)).toBe(90);
    expect(normaliseRotation(360)).toBe(0);
    expect(() => normaliseRotation(45)).toThrow(InvalidPageGeometryError);
  });

  it("refuses a page it cannot describe rather than inventing a default", () => {
    const base = {
      page: 1,
      crop_box: [0, 0, 612, 792],
      rotation: 0,
      user_unit: 1,
      native_width: 612,
      native_height: 792,
    };

    expect(() => parsePageGeometry({ ...base, crop_box: [0, 0, 612] })).toThrow(
      InvalidPageGeometryError,
    );
    expect(() => parsePageGeometry({ ...base, crop_box: [0, 0, 0, 792] })).toThrow(
      InvalidPageGeometryError,
    );
    expect(() => parsePageGeometry({ ...base, crop_box: [0, 0, Number.NaN, 792] })).toThrow(
      InvalidPageGeometryError,
    );
    expect(() => parsePageGeometry({ ...base, user_unit: 0 })).toThrow(InvalidPageGeometryError);
  });
});
