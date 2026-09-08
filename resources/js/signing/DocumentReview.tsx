import { useEffect, useMemo, useRef, useState } from "react";

import {
  type PageGeometry,
  PageTransform,
  parsePageGeometry,
} from "@/components/editor/PageTransform";
import type { LoadedPdf } from "@/components/editor/pdfjs";
import type { FieldDefinition } from "@/schema/fieldSchema";

import type { FieldValue, SigningPayload } from "./types";

/**
 * The document, rendered locally, with every field drawn where it actually sits.
 *
 * Two things are reused from the field editor rather than rewritten, and both are reuse of
 * the load-bearing kind:
 *
 * - **`PageTransform`.** It is the one place screen pixels become points, and
 *   `docs/preparation/coordinate-space.md` defines exactly one coordinate space. A signing
 *   page with its own conversion would be a second opinion about where a signature box is,
 *   and the two would disagree in precisely the cases that matter — a rotated page, a CropBox
 *   offset from the MediaBox. The geometry comes from the same preflight report the server
 *   validates rectangles against, so "the box is here" means one thing everywhere.
 * - **`loadPdf`.** PDF.js is configured there with a real worker and four local resource
 *   paths; leaving any of them unset makes the library fall back to its published CDN, which
 *   AGENTS.md forbids on this page above all others.
 *
 * ## What the overlay shows
 *
 * Every field, not only this signer's. A person is entitled to see the whole agreement,
 * including what another party will fill in — and a signature bound to a material digest
 * covering values the signer was never shown would be a signature on unseen text. Fields
 * belonging to somebody else are greyed and non-interactive; the signer's own are outlined
 * and act as links to the corresponding control below.
 *
 * The PDF is imported lazily, so a page that fails before it gets here never downloads a
 * megabyte of renderer to say so.
 */
export interface DocumentReviewProps {
  payload: SigningPayload;
  fields: FieldDefinition[];
  values: Readonly<Record<string, FieldValue>>;
  ownFieldIds: readonly string[];
  onFocusField: (fieldId: string) => void;
}

export function DocumentReview({
  payload,
  fields,
  values,
  ownFieldIds,
  onFocusField,
}: DocumentReviewProps) {
  const [pdf, setPdf] = useState<LoadedPdf | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [width, setWidth] = useState<number>(0);
  const container = useRef<HTMLDivElement | null>(null);

  const geometries = useMemo<PageGeometry[]>(() => {
    try {
      return payload.pages.map(parsePageGeometry);
    } catch {
      // A page this application cannot describe is a page it must not draw field boxes on.
      // Reporting it is the honest outcome; guessing a page size would put every field on
      // that page in the wrong place.
      return [];
    }
  }, [payload.pages]);

  useEffect(() => {
    const element = container.current;

    if (element === null) {
      return;
    }

    const measure = (): void => setWidth(element.clientWidth);
    measure();

    if (typeof ResizeObserver === "undefined") {
      window.addEventListener("resize", measure);

      return () => window.removeEventListener("resize", measure);
    }

    const observer = new ResizeObserver(measure);
    observer.observe(element);

    return () => observer.disconnect();
  }, []);

  useEffect(() => {
    let cancelled = false;
    let loaded: LoadedPdf | null = null;

    void (async () => {
      try {
        const { loadPdf } = await import("@/components/editor/pdfjs");
        loaded = await loadPdf(payload.urls.document);

        if (cancelled) {
          await loaded.destroy();

          return;
        }

        setPdf(loaded);
      } catch {
        if (!cancelled) {
          setError(
            "The document could not be displayed. Do not sign an agreement you cannot read — reload the page, and tell the sender if it keeps happening.",
          );
        }
      }
    })();

    return () => {
      cancelled = true;
      void loaded?.destroy();
    };
  }, [payload.urls.document]);

  if (error !== null) {
    return (
      <p className="text-destructive rounded-md border border-destructive/40 p-3 text-sm" role="alert">
        {error}
      </p>
    );
  }

  if (geometries.length === 0) {
    return (
      <p className="text-destructive rounded-md border border-destructive/40 p-3 text-sm" role="alert">
        This agreement's page geometry could not be read, so the fields cannot be shown in the
        right places. Do not sign; tell the sender.
      </p>
    );
  }

  return (
    <div ref={container} className="space-y-6">
      {geometries.map((geometry) => (
        <ReviewPage
          key={geometry.page}
          geometry={geometry}
          availableWidth={width}
          pdf={pdf}
          fields={fields.filter((field) => field.page === geometry.page)}
          values={values}
          ownFieldIds={ownFieldIds}
          onFocusField={onFocusField}
        />
      ))}
    </div>
  );
}

interface ReviewPageProps {
  geometry: PageGeometry;
  availableWidth: number;
  pdf: LoadedPdf | null;
  fields: FieldDefinition[];
  values: Readonly<Record<string, FieldValue>>;
  ownFieldIds: readonly string[];
  onFocusField: (fieldId: string) => void;
}

function ReviewPage({
  geometry,
  availableWidth,
  pdf,
  fields,
  values,
  ownFieldIds,
  onFocusField,
}: ReviewPageProps) {
  const canvas = useRef<HTMLCanvasElement | null>(null);

  // Fit to width, never magnified past 1:1. A signer who wants it larger pinches — which the
  // page deliberately does not block — and a page blown up to fill a desktop monitor is
  // harder to read, not easier.
  const transform = useMemo<PageTransform>(() => {
    const base = new PageTransform(geometry, 1);
    const scale = availableWidth > 0 ? Math.min(1, base.fitWidthScale(availableWidth)) : 1;

    return base.withScale(scale);
  }, [geometry, availableWidth]);

  useEffect(() => {
    const element = canvas.current;

    if (element === null || pdf === null) {
      return;
    }

    let cancelled = false;

    void (async () => {
      try {
        await pdf.renderPage(
          geometry.page,
          element,
          transform.cssWidth,
          transform.cssHeight,
          window.devicePixelRatio > 0 ? window.devicePixelRatio : 1,
        );
      } catch (cause) {
        const { isRenderCancellation } = await import("@/components/editor/pdfjs");

        if (!cancelled && !isRenderCancellation(cause)) {
          // Nothing to do here: the surrounding page already tells the signer not to sign a
          // document they cannot read, and a per-page message would bury that.
        }
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [pdf, geometry.page, transform]);

  return (
    <figure className="m-0">
      <div
        className="relative mx-auto bg-white shadow-sm ring-1 ring-black/10"
        style={{ width: `${transform.cssWidth}px`, height: `${transform.cssHeight}px` }}
      >
        <canvas
          ref={canvas}
          aria-hidden="true"
          className="absolute inset-0 block"
          style={{ width: `${transform.cssWidth}px`, height: `${transform.cssHeight}px` }}
        />

        {fields.map((field) => {
          const rect = transform.rectToScreen(field.rect);
          const mine = ownFieldIds.includes(field.id);
          const value = values[field.id] ?? null;

          return mine ? (
            <button
              key={field.id}
              type="button"
              onClick={() => onFocusField(field.id)}
              className="absolute rounded-sm border-2 border-dashed border-sky-600 bg-sky-500/10 text-left text-[10px] leading-none text-sky-900"
              style={{
                left: `${rect.left}px`,
                top: `${rect.top}px`,
                width: `${rect.width}px`,
                height: `${rect.height}px`,
              }}
            >
              <FieldMark value={value} label="Yours" />
            </button>
          ) : (
            <div
              key={field.id}
              aria-hidden="true"
              className="absolute rounded-sm border border-dashed border-black/25 bg-black/5 text-[10px] leading-none text-black/50"
              style={{
                left: `${rect.left}px`,
                top: `${rect.top}px`,
                width: `${rect.width}px`,
                height: `${rect.height}px`,
              }}
            >
              <FieldMark value={value} label="Another party" />
            </div>
          );
        })}
      </div>

      <figcaption className="text-muted-foreground mt-1 text-center text-xs tabular-nums">
        Page {geometry.page}
      </figcaption>
    </figure>
  );
}

/** A field's current contents, drawn inside its box. Images render; text is clipped. */
function FieldMark({ value, label }: { value: FieldValue; label: string }) {
  if (typeof value === "string" && value.startsWith("data:image/")) {
    return <img src={value} alt="" className="h-full w-full object-contain" />;
  }

  if (typeof value === "string" && value !== "") {
    return <span className="block truncate p-0.5">{value}</span>;
  }

  if (value === true) {
    return <span className="block p-0.5">✓</span>;
  }

  return <span className="block truncate p-0.5 opacity-70">{label}</span>;
}
