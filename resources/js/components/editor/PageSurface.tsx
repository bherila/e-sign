import { useEffect, useRef, useState } from "react";

import { cn } from "@/lib/utils";
import type { FieldDefinition, Rect } from "@/schema/fieldSchema";

import { FieldBox } from "./FieldBox";
import type { NativePoint, PageTransform } from "./PageTransform";
import type { LoadedPdf } from "./pdfjs";
import { colorFor, type RecipientColor } from "./recipientColors";

/**
 * One page: a PDF.js raster with the field overlay on top of it.
 *
 * The canvas is rasterised **lazily and released again**. A prepared document may run to
 * hundreds of pages, and a backing store for every one of them at device-pixel resolution is
 * hundreds of megabytes of memory for pages nobody is looking at. An `IntersectionObserver`
 * with a generous margin rasterises a page shortly before it scrolls into view and drops the
 * backing store once it is well out of it; the element keeps its exact CSS size throughout, so
 * scroll position never jumps and the overlay coordinates never move.
 *
 * Where `IntersectionObserver` is unavailable the page rasterises immediately. Degrading to
 * "render everything" is the honest fallback: it costs memory, and it never shows a blank page
 * where a document should be.
 *
 * The overlay is a sibling of the canvas at exactly the canvas's CSS size, so a field's
 * position is `PageTransform.rectToScreen(rect)` and nothing else — no offset parent to
 * measure, no scroll position to add.
 */
export interface PageSurfaceProps {
  pageNumber: number;
  transform: PageTransform;
  pdf: LoadedPdf | null;
  devicePixelRatio: number;
  fields: FieldDefinition[];
  recipientNames: Map<string, string>;
  colors: Map<string, RecipientColor>;
  selectedFieldId: string | null;
  readOnly: boolean;
  issueFieldIds: Set<string>;
  onSelect: (fieldId: string | null) => void;
  onMove: (fieldId: string, x: number, y: number) => void;
  onResize: (fieldId: string, rect: Rect) => void;
  onNudge: (fieldId: string, dx: number, dy: number) => void;
  onDelete: (fieldId: string) => void;
  /** Double-click on empty page area: place a new field of the armed type here. */
  onAddAt: (pageNumber: number, point: NativePoint) => void;
  onVisible: (pageNumber: number) => void;
  registerPage: (pageNumber: number, element: HTMLElement | null) => void;
}

/** How far outside the viewport a page starts rasterising, and how far out it is released. */
const RASTER_MARGIN = "600px";

export function PageSurface({
  pageNumber,
  transform,
  pdf,
  devicePixelRatio,
  fields,
  recipientNames,
  colors,
  selectedFieldId,
  readOnly,
  issueFieldIds,
  onSelect,
  onMove,
  onResize,
  onNudge,
  onDelete,
  onAddAt,
  onVisible,
  registerPage,
}: PageSurfaceProps) {
  const wrapper = useRef<HTMLDivElement | null>(null);
  const canvas = useRef<HTMLCanvasElement | null>(null);
  const [near, setNear] = useState(() => typeof IntersectionObserver === "undefined");
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const element = wrapper.current;

    if (element === null || typeof IntersectionObserver === "undefined") {
      return;
    }

    const observer = new IntersectionObserver(
      (entries) => {
        for (const entry of entries) {
          setNear(entry.isIntersecting);

          if (entry.isIntersecting) {
            onVisible(pageNumber);
          }
        }
      },
      { rootMargin: RASTER_MARGIN },
    );

    observer.observe(element);

    return () => observer.disconnect();
  }, [pageNumber, onVisible]);

  useEffect(() => {
    const element = canvas.current;

    if (element === null || pdf === null) {
      return;
    }

    if (!near) {
      // Release the backing store. The CSS size below keeps the layout identical.
      element.width = 0;
      element.height = 0;

      return;
    }

    let cancelled = false;

    void (async () => {
      try {
        await pdf.renderPage(
          pageNumber,
          element,
          transform.cssWidth,
          transform.cssHeight,
          devicePixelRatio,
        );

        if (!cancelled) {
          setError(null);
        }
      } catch (cause) {
        const { isRenderCancellation } = await import("./pdfjs");

        if (cancelled || isRenderCancellation(cause)) {
          return;
        }

        setError(cause instanceof Error ? cause.message : "This page could not be rendered.");
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [pdf, pageNumber, near, transform, devicePixelRatio]);

  return (
    <div className="flex flex-col items-center gap-1">
      <div
        ref={(element) => {
          wrapper.current = element;
          registerPage(pageNumber, element);
        }}
        data-page={pageNumber}
        className="relative bg-white shadow-sm ring-1 ring-black/10"
        style={{ width: `${transform.cssWidth}px`, height: `${transform.cssHeight}px` }}
        onDoubleClick={(event) => {
          if (readOnly || event.target !== event.currentTarget) {
            return;
          }

          const box = event.currentTarget.getBoundingClientRect();
          onAddAt(
            pageNumber,
            transform.screenToNative({
              left: event.clientX - box.left,
              top: event.clientY - box.top,
            }),
          );
        }}
      >
        <canvas
          ref={canvas}
          aria-hidden="true"
          className="absolute inset-0 block"
          style={{ width: `${transform.cssWidth}px`, height: `${transform.cssHeight}px` }}
        />

        {error === null ? null : (
          <p className="text-destructive absolute inset-x-2 top-2 text-xs">{error}</p>
        )}

        {fields.map((field) => (
          <FieldBox
            key={field.id}
            field={field}
            transform={transform}
            color={colorFor(colors, field.recipient_id)}
            recipientName={recipientNames.get(field.recipient_id) ?? "Unknown recipient"}
            selected={field.id === selectedFieldId}
            readOnly={readOnly}
            hasIssue={issueFieldIds.has(field.id)}
            onSelect={() => onSelect(field.id)}
            onMove={(x, y) => onMove(field.id, x, y)}
            onResize={(rect) => onResize(field.id, rect)}
            onNudge={(dx, dy) => onNudge(field.id, dx, dy)}
            onDelete={() => onDelete(field.id)}
          />
        ))}
      </div>

      <p className={cn("text-muted-foreground text-xs tabular-nums")}>
        Page {pageNumber} — {Math.round(transform.nativeWidth)} x {Math.round(transform.nativeHeight)} pt
        {transform.geometry.rotation === 0 ? "" : `, rotated ${transform.geometry.rotation}°`}
      </p>
    </div>
  );
}
