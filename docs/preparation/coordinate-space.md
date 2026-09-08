# The native coordinate space

**Status:** normative for Stage 0 onwards. Changing any line of the definition below is
a breaking change to stored field rectangles and needs an ADR.

Implemented by `app/Domain/Preparation/Geometry/`. Verified by
`tests/Unit/Preparation/Geometry/CoordinateTransformTest.php`, whose expectations are
hand-computed from this document rather than from the code.

## Definition

There is exactly one coordinate space in which field rectangles, anchor rectangles and
overlay positions are stored and exchanged.

| Property | Value |
|---|---|
| Unit | `pt`, i.e. one PDF default user-space unit (1/72 inch when `/UserUnit` is 1) |
| Origin | top-left corner of the displayed page |
| Axes | x increases to the right, y increases **downwards** |
| Page box | `/CropBox`, clipped to `/MediaBox`; a page without a `/CropBox` uses its `/MediaBox` |
| Rotation | as displayed: `/Rotate` is already applied |
| Page index | 1-based |
| `/UserUnit` | recorded on the page, never applied implicitly (see below) |

Serialised form, as it appears in every field-definition document:

```json
{
  "unit": "pt",
  "origin": "top-left",
  "page_box": "crop",
  "rotation": "displayed",
  "page_index_base": 1
}
```

`App\Domain\Preparation\Geometry\CoordinateSpace::assertSupported()` rejects a document
that omits any of these keys or declares a different value. There is no migration path
that silently reinterprets an older document.

## Why these choices

- **Top-left origin, y down.** It is what the browser editor works in and what a person
  means by "20 points below this line". Converting once, at the PDF boundary, is safer
  than converting in every UI component.
- **CropBox, not MediaBox.** The CropBox is what a viewer shows. A field placed relative
  to what the sender saw must be relative to the same box, or a document with a
  trim-marks MediaBox puts every signature in the wrong place.
- **Rotation as displayed.** A page with `/Rotate 90` is landscape to everyone who looks
  at it. Storing coordinates in the unrotated space would make the editor and the API
  disagree about which dimension is "width".
- **1-based pages.** The API, the editor and every human description of a contract count
  pages from one.

## The transform

`CoordinateTransform` converts between native space and PDF default user space for one
page. Let the effective CropBox be `[x0, y0, x1, y1]`, with `cw = x1 - x0` and
`ch = y1 - y0`.

**Displayed page size**

| `/Rotate` | native width | native height |
|---|---|---|
| 0, 180 | `cw` | `ch` |
| 90, 270 | `ch` | `cw` |

**Native `(nx, ny)` to user space**, in two steps. First undo the display rotation to get
unrotated page coordinates `(px, py)` measured from the top-left of the CropBox:

| `/Rotate` | `px` | `py` |
|---|---|---|
| 0 | `nx` | `ny` |
| 90 | `ny` | `ch - nx` |
| 180 | `cw - nx` | `ch - ny` |
| 270 | `cw - ny` | `nx` |

Then flip the y axis and add the box origin:

```
ux = x0 + px
uy = y0 + (ch - py)
```

The inverse is the same table read backwards; `CoordinateTransform::toNative()` writes it
out explicitly rather than inverting a matrix, so the sign conventions stay reviewable.

**Corner check.** Under `/Rotate 90` the displayed top-left corner is the stored
bottom-left corner of the CropBox: native `(0, 0)` maps to user `(x0, y0)`. Under
`/Rotate 180` it is the stored bottom-right corner, and under `/Rotate 270` the stored
top-right corner. Those four cases are asserted directly in the unit tests.

**Rectangles.** Quarter turns keep axis-aligned rectangles axis-aligned, so a native
rectangle maps to a user-space rectangle by transforming two opposite corners and
re-normalising. Under 90 or 270 the width and height transpose. This is exact, not an
approximation.

## `/UserUnit`

`/UserUnit` scales the physical size of a default user-space unit. A page with
`/UserUnit 2.0` and a 612x792 CropBox is 612x792 *units* and 1224x1584 *physical points*.

The rule is: **native coordinates are default user-space units. `/UserUnit` never
rescales them.**

- `PageGeometry::$userUnit` records the value, and preflight warns when it is not 1.
- Physical size is available only through explicitly named conversions:
  `PageGeometry::physicalWidthPt()`, `CoordinateTransform::nativeToPhysicalPoints()`,
  `CoordinateTransform::physicalPointsToNative()`, `rectToPhysicalPoints()`.

The alternative — defining native units as physical points — would make every stored
rectangle depend on a page attribute that the viewer, the editor and the import engine
each treat differently. Keeping native units equal to the numbers in the page boxes means
the editor, the preview and the assembled output agree without any of them having to know
about `/UserUnit`.

The import engine does not carry `/UserUnit` into its output (see
`docs/stage0/pdf-import.md`), which is a fidelity gap the preflight reports rather than
something this transform papers over.

## Facade conversions are declared, never inferred

> Never infer percent versus points from a number's magnitude.

The consumer's client documents a 0-100 percentage convention for field coordinates; the
reviewed compatibility examples contain values above 100. Both are legitimate encodings
of a position, and `{"x": 60}` is indistinguishable between them. Any rule of the form
"values under 100 are percentages" is a guess that silently misplaces a signature the
first time a field lands in the left 100 points of a page.

Therefore:

1. Every compatibility profile declares its convention as one of the values of
   `DeclaredCoordinateConvention` (`native_points_top_left`, `percent_of_page_top_left`,
   `points_bottom_left`).
2. `FacadeCoordinateTranslator` converts a declared convention to native and back. Both
   directions are total.
3. A request that arrives without a declaration raises
   `UndeclaredCoordinateConventionException`. It is not defaulted, and it is not guessed
   from the values.
4. The declared convention is recorded in the capability matrix for the profile, and each
   conversion is backed by a fixture test asserting that a field placed through the facade
   lands on exactly the rectangle the equivalent native JSON produces.

Percentages are always relative to the **displayed** page, so a `/Rotate 90` Letter page
treats 50% x as 396 pt, not 306 pt.

## Validation rules that follow from the space

- A rectangle must lie inside the displayed page: `CoordinateTransform::containsRect()`.
- Coordinates must be finite; `NativeRect` and `PageBox` reject `NAN` and `INF`.
- Widths and heights must not be negative; page boxes must have positive extent.
- `/Rotate` must be a multiple of 90. Negative and above-360 values are normalised;
  anything else is an error rather than a rounding.
- A `/CropBox` is intersected with the `/MediaBox`. A CropBox that does not overlap the
  MediaBox is a malformed page and is rejected.
