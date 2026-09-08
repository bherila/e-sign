import { type PointerEvent as ReactPointerEvent, useCallback, useEffect, useId, useRef, useState } from "react";

import {
  decodedByteLength,
  drawnSignatureDataUrl,
  isBlank,
  type Point,
  SignatureCaptureError,
  type Stroke,
  typedSignatureDataUrl,
} from "./signatureImage";

/**
 * Adopting a signature or a set of initials: type it, or draw it.
 *
 * ## The typed path is the default, and that is a decision
 *
 * docs/HANDOFF.md section 8 requires "an accessible alternative", and issue #26 spells it out
 * as "typed with keyboard". Offering it *second*, behind a drawing pad, satisfies the letter
 * of that and not the point: a person using a keyboard, a switch device, or a screen reader
 * cannot draw, and a signing flow whose primary gesture is a mouse drag excludes them from
 * agreeing to a contract. So typing is the tab that opens, and the consent notice says the two
 * carry the same weight — which is true, because the mark is not what makes the signature.
 *
 * ## Adoption is explicit
 *
 * Drawing something does not fill the field. The signer presses **Use this signature**, and
 * only then is a value produced. That is the same distinction docs/HANDOFF.md draws when it
 * says never to record a signature merely because a canvas is non-empty: a mark on a canvas is
 * a mark on a canvas until somebody adopts it.
 *
 * A drawing below {@link isBlank}'s threshold cannot be adopted at all, so an accidental tap
 * never becomes a signature.
 *
 * ## Pointer events, and why `touch-action: none`
 *
 * One handler set covers mouse, pen, and touch. Without `touch-action: none` on the canvas a
 * touch drag scrolls the page instead of drawing, which on a phone makes the drawn path
 * unusable. It is scoped to the canvas alone: the rest of the page — including the PDF —
 * keeps its native scrolling and pinch-zoom, which a signer reading small print needs.
 */
export interface SignatureCaptureProps {
  fieldId: string;
  label: string;
  /** `signature` or `initials`. Only the wording differs. */
  kind: "signature" | "initials";
  /** The recipient's name, offered as the typed default. */
  suggestedName: string;
  value: string | null;
  maxBytes: number;
  onAdopt: (dataUrl: string) => void;
  onClear: () => void;
}

/** Drawing surface size in CSS pixels. Fits a 375px viewport with the page's own padding. */
const SURFACE_WIDTH = 320;
const SURFACE_HEIGHT = 110;

export function SignatureCapture({
  fieldId,
  label,
  kind,
  suggestedName,
  value,
  maxBytes,
  onAdopt,
  onClear,
}: SignatureCaptureProps) {
  const [mode, setMode] = useState<"typed" | "drawn">("typed");
  const [typed, setTyped] = useState(suggestedName);
  const [strokes, setStrokes] = useState<Stroke[]>([]);
  const [error, setError] = useState<string | null>(null);
  const canvas = useRef<HTMLCanvasElement | null>(null);
  const drawing = useRef<boolean>(false);
  const headingId = useId();

  const redraw = useCallback((): void => {
    const element = canvas.current;

    if (element === null || mode !== "drawn") {
      return;
    }

    try {
      // Rendering the live preview through the same helper the adoption uses means what the
      // signer sees is what would be produced, rather than a second drawing routine that
      // could differ from it.
      const context = element.getContext("2d");

      if (context === null) {
        return;
      }

      const ratio = window.devicePixelRatio > 0 ? window.devicePixelRatio : 1;
      element.width = Math.round(SURFACE_WIDTH * ratio);
      element.height = Math.round(SURFACE_HEIGHT * ratio);
      context.setTransform(ratio, 0, 0, ratio, 0, 0);
      context.clearRect(0, 0, SURFACE_WIDTH, SURFACE_HEIGHT);
      context.lineWidth = 2.5;
      context.lineCap = "round";
      context.lineJoin = "round";
      context.strokeStyle = "#111111";

      for (const stroke of strokes) {
        const first = stroke[0];

        if (first === undefined) {
          continue;
        }

        context.beginPath();
        context.moveTo(first.x, first.y);

        for (let i = 1; i < stroke.length; i += 1) {
          const point = stroke[i];

          if (point !== undefined) {
            context.lineTo(point.x, point.y);
          }
        }

        context.stroke();
      }
    } catch {
      // A browser with no 2D context cannot draw. The typed path still works, and saying so
      // is more useful than a blank box.
      setError("Drawing is not available in this browser. Type your name instead.");
      setMode("typed");
    }
  }, [mode, strokes]);

  useEffect(redraw, [redraw]);

  function pointFrom(event: ReactPointerEvent<HTMLCanvasElement>): Point {
    const box = event.currentTarget.getBoundingClientRect();

    return { x: event.clientX - box.left, y: event.clientY - box.top };
  }

  function adopt(): void {
    const element = canvas.current;

    if (element === null) {
      setError("This browser did not provide a drawing surface.");

      return;
    }

    const options = {
      width: SURFACE_WIDTH,
      height: SURFACE_HEIGHT,
      devicePixelRatio: window.devicePixelRatio > 0 ? window.devicePixelRatio : 1,
    };

    try {
      const dataUrl =
        mode === "typed"
          ? typedSignatureDataUrl(element, typed, options)
          : drawnSignatureDataUrl(element, strokes, options);

      if (decodedByteLength(dataUrl) > maxBytes) {
        // The server enforces this too and would refuse the save. Catching it here means the
        // signer is told about their signature rather than about a field validation rule.
        setError("That signature is too large to store. Try a simpler mark.");

        return;
      }

      setError(null);
      onAdopt(dataUrl);
    } catch (cause) {
      setError(
        cause instanceof SignatureCaptureError
          ? cause.message
          : "That signature could not be captured. Try again, or type your name instead.",
      );
    } finally {
      // The canvas has just been used as a scratch surface by the export helpers, so the
      // preview has to be repainted whichever path ran.
      redraw();
    }
  }

  const noun = kind === "initials" ? "initials" : "signature";

  return (
    <fieldset className="rounded-md border border-black/15 p-3 dark:border-white/15" aria-labelledby={headingId}>
      <legend id={headingId} className="px-1 text-sm font-medium">
        {label}
      </legend>

      {value === null ? null : (
        <div className="mb-3">
          <p className="text-muted-foreground text-xs">Adopted {noun}</p>
          <img
            src={value}
            alt={`Your adopted ${noun}`}
            className="mt-1 max-h-24 w-auto bg-white"
          />
          <button
            type="button"
            onClick={() => {
              setError(null);
              onClear();
            }}
            className="mt-2 inline-flex min-h-11 items-center rounded-md border border-black/20 px-3 text-sm dark:border-white/20"
          >
            Change it
          </button>
        </div>
      )}

      {value !== null ? null : (
        <>
          <div className="flex gap-2" role="tablist" aria-label={`How to provide your ${noun}`}>
            <button
              type="button"
              role="tab"
              aria-selected={mode === "typed"}
              onClick={() => {
                setMode("typed");
                setError(null);
              }}
              className={tabClass(mode === "typed")}
            >
              Type it
            </button>
            <button
              type="button"
              role="tab"
              aria-selected={mode === "drawn"}
              onClick={() => {
                setMode("drawn");
                setError(null);
              }}
              className={tabClass(mode === "drawn")}
            >
              Draw it
            </button>
          </div>

          <div className="mt-3">
            {mode === "typed" ? (
              <label className="block">
                <span className="text-sm">Your name, as you want it to appear</span>
                <input
                  type="text"
                  value={typed}
                  onChange={(event) => setTyped(event.target.value)}
                  autoComplete="name"
                  className="mt-1 block w-full min-h-11 rounded-md border border-black/20 bg-transparent px-3 text-base dark:border-white/20"
                />
              </label>
            ) : (
              <>
                <canvas
                  ref={canvas}
                  width={SURFACE_WIDTH}
                  height={SURFACE_HEIGHT}
                  aria-label={`Draw your ${noun} here`}
                  className="block w-full max-w-[320px] rounded-md border border-dashed border-black/30 bg-white dark:border-white/30"
                  style={{ height: `${SURFACE_HEIGHT}px`, touchAction: "none" }}
                  onPointerDown={(event) => {
                    event.currentTarget.setPointerCapture(event.pointerId);
                    drawing.current = true;
                    setStrokes((previous) => [...previous, [pointFrom(event)]]);
                  }}
                  onPointerMove={(event) => {
                    if (!drawing.current) {
                      return;
                    }

                    const point = pointFrom(event);
                    setStrokes((previous) => {
                      const next = previous.slice();
                      const last = next[next.length - 1];

                      if (last === undefined) {
                        return previous;
                      }

                      next[next.length - 1] = [...last, point];

                      return next;
                    });
                  }}
                  onPointerUp={() => {
                    drawing.current = false;
                  }}
                  onPointerCancel={() => {
                    drawing.current = false;
                  }}
                />
                <button
                  type="button"
                  onClick={() => {
                    setStrokes([]);
                    setError(null);
                  }}
                  className="mt-2 inline-flex min-h-11 items-center rounded-md border border-black/20 px-3 text-sm dark:border-white/20"
                >
                  Clear
                </button>
              </>
            )}
          </div>

          <button
            type="button"
            onClick={adopt}
            disabled={mode === "drawn" && isBlank(strokes)}
            data-testid={`adopt-${fieldId}`}
            className="mt-3 inline-flex min-h-11 w-full items-center justify-center rounded-md bg-foreground px-4 text-base font-medium text-background disabled:opacity-50 sm:w-auto sm:px-6"
          >
            Use this {noun}
          </button>

          <p className="text-muted-foreground mt-2 text-xs">
            Typing your name and drawing it carry the same weight. Nothing is signed until you
            agree at the bottom of this page.
          </p>
        </>
      )}

      {error === null ? null : (
        <p className="text-destructive mt-2 text-sm" role="alert">
          {error}
        </p>
      )}
    </fieldset>
  );
}

function tabClass(active: boolean): string {
  return [
    "inline-flex min-h-11 items-center rounded-md px-4 text-sm font-medium",
    active
      ? "bg-foreground text-background"
      : "border border-black/20 dark:border-white/20",
  ].join(" ");
}
