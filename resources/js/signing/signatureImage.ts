/**
 * Turning a person's gesture — or their typed name — into a PNG data URL.
 *
 * Everything in here runs in the browser and none of it is trusted. The server decodes what
 * it receives with GD, measures it, and re-encodes it
 * (`App\Domain\Signing\Capture\SignatureImage`), so the value that reaches the agreement is
 * bytes PHP produced from a pixel buffer. What this module is for is producing something
 * *reasonable* to send, and — more importantly — refusing to call a stray tap a signature.
 *
 * ## "Never record a signature merely because a canvas is nonempty"
 *
 * That is docs/HANDOFF.md section 8, and it is the reason {@link isBlank} exists and is
 * strict. A single click leaves a dot; a pocket touch leaves a two-pixel scratch; a canvas
 * that has been drawn on and erased still has a non-empty stroke array. So the test is total
 * path length across all strokes, against {@link MIN_STROKE_LENGTH}, not "are there any
 * points". The mark is not the signature either way — the consent tick, the stated intent,
 * and the server's attestation are — but offering to sign with an accidental smudge on the
 * page is not a state this UI should be able to reach.
 *
 * ## Why the canvas is an interface
 *
 * `SignatureCanvas` and `SignatureContext` are structural subsets of `HTMLCanvasElement` and
 * `CanvasRenderingContext2D`, so a real canvas satisfies them without a cast. jsdom has no
 * 2D context, so the alternative to this indirection would be either an untested module or a
 * native `canvas` dependency in devDependencies — a compiled module, for a build that must
 * stay `pnpm install` on a shared host.
 */

/** A point in CSS pixels, relative to the drawing surface's top-left corner. */
export interface Point {
  x: number;
  y: number;
}

/** One continuous pointer-down-to-pointer-up gesture. */
export type Stroke = Point[];

/**
 * The subset of `CanvasRenderingContext2D` this module uses.
 *
 * Deliberately small. Every member here is one a test double has to implement, and every
 * member added is a reason for the double to drift from the real thing.
 */
export interface SignatureContext {
  lineWidth: number;
  lineCap: CanvasLineCap;
  lineJoin: CanvasLineJoin;
  strokeStyle: string | CanvasGradient | CanvasPattern;
  fillStyle: string | CanvasGradient | CanvasPattern;
  font: string;
  textBaseline: CanvasTextBaseline;
  textAlign: CanvasTextAlign;
  clearRect(x: number, y: number, width: number, height: number): void;
  beginPath(): void;
  moveTo(x: number, y: number): void;
  lineTo(x: number, y: number): void;
  stroke(): void;
  fillText(text: string, x: number, y: number): void;
  measureText(text: string): { width: number };
  setTransform(a: number, b: number, c: number, d: number, e: number, f: number): void;
}

/** The subset of `HTMLCanvasElement` this module uses. */
export interface SignatureCanvas {
  width: number;
  height: number;
  getContext(contextId: "2d"): SignatureContext | null;
  toDataURL(type?: string): string;
}

export interface RasterOptions {
  /** CSS pixels. The signature box's on-screen size. */
  width: number;
  height: number;
  /** Backing-store multiplier, so a stroke is not soft on a high-density display. */
  devicePixelRatio: number;
}

/**
 * Total path length, in CSS pixels, below which a drawing is not offered as a signature.
 *
 * Chosen to sit above an accidental tap or a two-finger scroll that leaked one pointer event,
 * and well below the shortest deliberate mark anybody makes — a pair of initials drawn in a
 * 40pt-high box runs to several hundred pixels of path.
 */
export const MIN_STROKE_LENGTH = 40;

/** The only output shape. Both paths produce this; the server accepts nothing else. */
export const PNG_DATA_URL_PREFIX = "data:image/png;base64,";

export class SignatureCaptureError extends Error {
  constructor(message: string) {
    super(message);
    this.name = "SignatureCaptureError";
  }
}

/** Sum of the straight-line distances between consecutive points, across every stroke. */
export function totalStrokeLength(strokes: readonly Stroke[]): number {
  let total = 0;

  for (const stroke of strokes) {
    for (let i = 1; i < stroke.length; i += 1) {
      const from = stroke[i - 1];
      const to = stroke[i];

      if (from === undefined || to === undefined) {
        continue;
      }

      total += Math.hypot(to.x - from.x, to.y - from.y);
    }
  }

  return total;
}

/**
 * True when this drawing must not be offered as a signature.
 *
 * See the module docblock: length, not emptiness.
 */
export function isBlank(strokes: readonly Stroke[]): boolean {
  return totalStrokeLength(strokes) < MIN_STROKE_LENGTH;
}

/**
 * Prepare a canvas for drawing at `devicePixelRatio` and clear it.
 *
 * `setTransform` rather than `scale`, because `scale` compounds: calling it on every redraw
 * would make each frame twice the size of the last one, which is the classic high-DPI canvas
 * bug and is invisible at ratio 1.
 */
export function prepare(canvas: SignatureCanvas, options: RasterOptions): SignatureContext {
  const ratio = options.devicePixelRatio > 0 ? options.devicePixelRatio : 1;

  canvas.width = Math.max(1, Math.round(options.width * ratio));
  canvas.height = Math.max(1, Math.round(options.height * ratio));

  const context = canvas.getContext("2d");

  if (context === null) {
    throw new SignatureCaptureError("This browser did not provide a drawing surface.");
  }

  context.setTransform(ratio, 0, 0, ratio, 0, 0);
  context.clearRect(0, 0, options.width, options.height);

  return context;
}

/** Paint the strokes. Transparent background: the mark is a stroke on nothing. */
export function drawStrokes(
  context: SignatureContext,
  strokes: readonly Stroke[],
  colour = "#111111",
): void {
  context.lineWidth = 2.5;
  context.lineCap = "round";
  context.lineJoin = "round";
  context.strokeStyle = colour;

  for (const stroke of strokes) {
    const first = stroke[0];

    if (first === undefined) {
      continue;
    }

    context.beginPath();
    context.moveTo(first.x, first.y);

    // A single-point stroke draws a dot rather than nothing, so a deliberate full stop in a
    // signature survives. It is still not enough on its own to pass `isBlank`.
    if (stroke.length === 1) {
      context.lineTo(first.x + 0.01, first.y);
    }

    for (let i = 1; i < stroke.length; i += 1) {
      const point = stroke[i];

      if (point !== undefined) {
        context.lineTo(point.x, point.y);
      }
    }

    context.stroke();
  }
}

/**
 * Paint a typed name as the signature mark.
 *
 * This is the accessible default, not a fallback. A drawn signature needs a pointing device
 * and a steady hand; somebody using a keyboard, a screen reader, or a switch device has
 * neither, and a product whose only signing gesture is a mouse drag excludes them from
 * agreeing to a contract. So the typed path is offered first, and the consent notice says the
 * two carry the same weight — which is true, because neither the drawing nor the lettering is
 * what makes the signature.
 *
 * The face is a plain system stack rather than a script font. A cursive webfont would be a
 * third-party origin (forbidden here) or a bundled asset pretending a keyboard produced
 * handwriting; a legible name is more honest and reproduces identically wherever the sealed
 * PDF is opened.
 *
 * The size is fitted to the box by measurement rather than guessed, so a long name shrinks
 * instead of overflowing into the surrounding text.
 */
export function drawTypedName(
  context: SignatureContext,
  text: string,
  options: RasterOptions,
  colour = "#111111",
): void {
  const trimmed = text.trim();

  if (trimmed === "") {
    throw new SignatureCaptureError("Type your name to use it as your signature.");
  }

  const fontFamily = '"Helvetica Neue", Helvetica, Arial, sans-serif';
  const maxSize = Math.max(10, Math.floor(options.height * 0.6));
  let size = maxSize;

  // Shrink until it fits the width, with a floor: below 10px it is unreadable and the box is
  // simply too small for the name, which the caller reports rather than rendering illegibly.
  for (; size > 10; size -= 1) {
    context.font = `${size}px ${fontFamily}`;

    if (context.measureText(trimmed).width <= options.width * 0.92) {
      break;
    }
  }

  context.font = `${size}px ${fontFamily}`;
  context.fillStyle = colour;
  context.textBaseline = "middle";
  context.textAlign = "center";
  context.fillText(trimmed, options.width / 2, options.height / 2);
}

/**
 * Read the canvas back as a PNG data URL, and refuse anything else.
 *
 * `toDataURL()` defaults to PNG, but a browser that returned something else — or a test
 * double that lies — must not slip a JPEG or, worse, an empty string past this point. The
 * server would reject it; failing here means the signer is told to try again instead of
 * seeing a validation error about a field they did fill in.
 */
export function exportPng(canvas: SignatureCanvas): string {
  const dataUrl = canvas.toDataURL("image/png");

  if (!dataUrl.startsWith(PNG_DATA_URL_PREFIX) || dataUrl.length <= PNG_DATA_URL_PREFIX.length) {
    throw new SignatureCaptureError("This browser did not produce a usable signature image.");
  }

  return dataUrl;
}

/** Approximate decoded size of a base64 data URL, for the client-side size check. */
export function decodedByteLength(dataUrl: string): number {
  const comma = dataUrl.indexOf(",");
  const payload = comma === -1 ? "" : dataUrl.slice(comma + 1);
  const padding = payload.endsWith("==") ? 2 : payload.endsWith("=") ? 1 : 0;

  return Math.max(0, Math.floor((payload.length * 3) / 4) - padding);
}

/** Drawn signature: strokes in, PNG data URL out. */
export function drawnSignatureDataUrl(
  canvas: SignatureCanvas,
  strokes: readonly Stroke[],
  options: RasterOptions,
): string {
  if (isBlank(strokes)) {
    throw new SignatureCaptureError(
      "That is not enough of a mark to be a signature. Draw your signature, or type your name instead.",
    );
  }

  const context = prepare(canvas, options);
  drawStrokes(context, strokes);

  return exportPng(canvas);
}

/** Typed signature: a name in, PNG data URL out. */
export function typedSignatureDataUrl(
  canvas: SignatureCanvas,
  text: string,
  options: RasterOptions,
): string {
  const context = prepare(canvas, options);
  drawTypedName(context, text, options);

  return exportPng(canvas);
}
