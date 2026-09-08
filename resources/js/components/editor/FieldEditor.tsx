import { useCallback, useEffect, useMemo, useReducer, useRef, useState } from "react";

import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import {
  describeIssue,
  type FieldDefinition,
  type FieldSchemaDocument,
  FieldSchemaError,
  type FieldType,
  type PageSize,
  parseFieldSchema,
  validateFieldSchema,
  type ValidationIssue,
} from "@/schema/fieldSchema";

import { readVersion, saveFieldSchema, type ServerIssue } from "./api";
import { canRedo, canUndo, createEditorState, createField, editorReducer, findField, isDirty } from "./editorReducer";
import { clampZoom,EditorToolbar } from "./EditorToolbar";
import { FieldInspector } from "./FieldInspector";
import { FieldTable } from "./FieldTable";
import { ImportExportPanel } from "./ImportExportPanel";
import { PageSurface } from "./PageSurface";
import { type PageGeometry, pageSizesOf, PageTransform, parsePageGeometry } from "./PageTransform";
import type { LoadedPdf } from "./pdfjs";
import { recipientColors } from "./recipientColors";
import { RecipientLegend } from "./RecipientLegend";
import type { EditorPayload } from "./types";
import { useDevicePixelRatio } from "./useDevicePixelRatio";
import { ValidationPanel } from "./ValidationPanel";

/**
 * The visual field editor (issue #22).
 *
 * Composition only. Every rule it depends on lives somewhere it can be tested on its own:
 * coordinates in `PageTransform`, document mutation and history in `editorReducer`, the
 * document vocabulary in `@/schema/fieldSchema`, rendering in `pdfjs.ts`, the two network
 * calls in `api.ts`. Nothing here converts a unit, invents a default, or decides what a valid
 * document is.
 *
 * Two views over one document, and they are peers: the page view is faster with a pointer, the
 * fields table is complete without one. Both dispatch the same actions to the same reducer, so
 * neither can drift into being the "real" one.
 */
export interface FieldEditorProps {
  payload: EditorPayload;
}

interface LoadedPayload {
  document: FieldSchemaDocument;
  pages: PageGeometry[];
}

export default function FieldEditor({ payload }: FieldEditorProps) {
  const loaded = useMemo<LoadedPayload | { failure: string; issues: ValidationIssue[] }>(() => {
    let pages: PageGeometry[];

    try {
      pages = payload.pages.map(parsePageGeometry);
    } catch (error) {
      return {
        failure: error instanceof Error ? error.message : "The page geometry could not be read.",
        issues: [],
      };
    }

    try {
      return { document: parseFieldSchema(payload.field_schema, { pageSizes: pageSizesOf(pages) }), pages };
    } catch (error) {
      return {
        failure:
          error instanceof Error
            ? error.message
            : "The stored field set could not be read.",
        issues: error instanceof FieldSchemaError ? error.issues : [],
      };
    }
  }, [payload]);

  if ("failure" in loaded) {
    return (
      <Alert variant="destructive">
        <AlertTitle>This version's field set could not be opened.</AlertTitle>
        <AlertDescription>
          <p>{loaded.failure}</p>
          {loaded.issues.length === 0 ? null : (
            <ul className="mt-2 flex flex-col gap-0.5 font-mono text-xs">
              {loaded.issues.map((issue, index) => (
                <li key={`${issue.path}-${index}`}>{describeIssue(issue)}</li>
              ))}
            </ul>
          )}
          <p className="mt-2">
            The canonical bytes are still available at{" "}
            <a className="underline underline-offset-4" href={payload.urls.schema}>
              {payload.urls.schema}
            </a>
            . Nothing was changed.
          </p>
        </AlertDescription>
      </Alert>
    );
  }

  return <LoadedFieldEditor payload={payload} initial={loaded.document} pages={loaded.pages} />;
}

interface LoadedFieldEditorProps {
  payload: EditorPayload;
  initial: FieldSchemaDocument;
  pages: PageGeometry[];
}

function LoadedFieldEditor({ payload, initial, pages }: LoadedFieldEditorProps) {
  const [state, dispatch] = useReducer(editorReducer, initial, createEditorState);
  const [zoom, setZoom] = useState(1);
  const [fitWidth, setFitWidth] = useState(true);
  const [currentPage, setCurrentPage] = useState(1);
  const [pdf, setPdf] = useState<LoadedPdf | null>(null);
  const [pdfError, setPdfError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [savedSha, setSavedSha] = useState(payload.field_schema_sha256);
  const [serverMessage, setServerMessage] = useState<string | null>(null);
  const [serverIssues, setServerIssues] = useState<ServerIssue[]>([]);
  const [conflict, setConflict] = useState<string | null>(null);
  const [newFieldType, setNewFieldType] = useState<FieldType>("signature");
  const [newFieldRecipient, setNewFieldRecipient] = useState(
    () => initial.recipients[0]?.id ?? "",
  );

  const devicePixelRatio = useDevicePixelRatio();
  const surface = useRef<HTMLDivElement | null>(null);
  const pageElements = useRef(new Map<number, HTMLElement>());

  const readOnly = payload.read_only;
  const pageSizes = useMemo<PageSize[]>(() => pageSizesOf(pages), [pages]);
  const pageCount = pages.length;
  const dirty = useMemo(() => isDirty(state), [state]);

  const colors = useMemo(
    () => recipientColors(state.document.recipients.map((recipient) => recipient.id)),
    [state.document.recipients],
  );

  const recipientNames = useMemo(() => {
    const names = new Map<string, string>();

    for (const recipient of state.document.recipients) {
      names.set(recipient.id, recipient.name);
    }

    return names;
  }, [state.document.recipients]);

  const fieldCounts = useMemo(() => {
    const counts = new Map<string, number>();

    for (const field of state.document.fields) {
      counts.set(field.recipient_id, (counts.get(field.recipient_id) ?? 0) + 1);
    }

    return counts;
  }, [state.document.fields]);

  const issues = useMemo(
    () =>
      validateFieldSchema(state.document, {
        pageSizes,
        // Omitting the list is not the same as passing an empty one: with no variables the
        // check is skipped, which is what a template without a sending context means. Passing
        // `[]` would report every prefill as unresolvable.
        ...(payload.variables.length > 0 ? { variables: payload.variables } : {}),
      }),
    [state.document, pageSizes, payload.variables],
  );

  const issueFieldIds = useMemo(() => {
    const ids = new Set<string>();

    for (const issue of issues) {
      const id = fieldIdForPath(state.document, issue.path);

      if (id !== null) {
        ids.add(id);
      }
    }

    for (const issue of serverIssues) {
      const id = fieldIdForPath(state.document, issue.path);

      if (id !== null) {
        ids.add(id);
      }
    }

    return ids;
  }, [issues, serverIssues, state.document]);

  const selectedField: FieldDefinition | null =
    state.selectedFieldId === null ? null : findField(state.document, state.selectedFieldId) ?? null;

  // ------------------------------------------------------------------------------ the PDF

  useEffect(() => {
    let cancelled = false;
    let opened: LoadedPdf | null = null;

    void (async () => {
      try {
        const { loadPdf } = await import("./pdfjs");
        opened = await loadPdf(payload.urls.document_view);

        if (cancelled) {
          void opened.destroy();

          return;
        }

        setPdf(opened);
      } catch (error) {
        if (!cancelled) {
          setPdfError(
            error instanceof Error ? error.message : "The document could not be opened.",
          );
        }
      }
    })();

    return () => {
      cancelled = true;
      void opened?.destroy();
    };
  }, [payload.urls.document_view]);

  // ------------------------------------------------------------------------------- zoom

  const applyFitWidth = useCallback(() => {
    const element = surface.current;

    if (element === null || pages.length === 0) {
      return;
    }

    const available = element.clientWidth - 32;

    if (available <= 0) {
      return;
    }

    // The widest page decides, so every page fits rather than the first one fitting and a
    // landscape page overflowing.
    const scale = Math.min(
      ...pages.map((page) => new PageTransform(page, 1).fitWidthScale(available)),
    );

    setZoom(clampZoom(scale));
  }, [pages]);

  useEffect(() => {
    if (!fitWidth) {
      return;
    }

    applyFitWidth();

    if (typeof window === "undefined") {
      return;
    }

    window.addEventListener("resize", applyFitWidth);

    return () => window.removeEventListener("resize", applyFitWidth);
  }, [fitWidth, applyFitWidth]);

  // ------------------------------------------------------------------------------- pages

  const registerPage = useCallback((pageNumber: number, element: HTMLElement | null) => {
    if (element === null) {
      pageElements.current.delete(pageNumber);
    } else {
      pageElements.current.set(pageNumber, element);
    }
  }, []);

  const goToPage = useCallback(
    (pageNumber: number) => {
      const clamped = Math.min(Math.max(pageNumber, 1), Math.max(pageCount, 1));
      setCurrentPage(clamped);

      const element = pageElements.current.get(clamped);

      if (element !== undefined && typeof element.scrollIntoView === "function") {
        element.scrollIntoView({ block: "start", behavior: "smooth" });
      }
    },
    [pageCount],
  );

  const onPageVisible = useCallback((pageNumber: number) => setCurrentPage(pageNumber), []);

  // ------------------------------------------------------------------------------ saving

  const persist = useCallback(
    async (force: boolean) => {
      setSaving(true);
      setServerMessage(null);
      setServerIssues([]);
      setConflict(null);

      if (!force) {
        const remote = await readVersion(payload.urls.version);

        if (remote !== null && remote.fieldSchemaSha256 !== savedSha) {
          setConflict(
            "This version's field set changed on the server since it was opened here. " +
              "Saving now would overwrite that change.",
          );
          setSaving(false);

          return;
        }
      }

      const outcome = await saveFieldSchema(payload.urls.save, payload.csrf_token, state.document);
      setSaving(false);

      switch (outcome.status) {
        case "saved":
          setSavedSha(outcome.version.fieldSchemaSha256);
          dispatch({ type: "mark_saved", document: state.document });
          break;
        case "rejected":
          setServerMessage(outcome.message);
          setServerIssues(outcome.issues);
          break;
        case "conflict":
          setServerMessage(`${outcome.message} (${outcome.code})`);
          break;
        case "failed":
          setServerMessage(outcome.message);
          break;
      }
    },
    [payload.urls.version, payload.urls.save, payload.csrf_token, savedSha, state.document],
  );

  const reloadFromServer = useCallback(async () => {
    const response = await fetch(payload.urls.schema, {
      credentials: "include",
      headers: { Accept: "application/json" },
    });

    if (!response.ok) {
      setServerMessage(`Could not re-read the saved field set (${response.status}).`);

      return;
    }

    const digest = response.headers.get("X-Field-Schema-Sha256");
    const text = await response.text();

    try {
      const document = parseFieldSchema(text, { pageSizes });
      dispatch({ type: "import_document", document });
      dispatch({ type: "mark_saved", document });
      setSavedSha(digest ?? "");
      setConflict(null);
      setServerMessage(null);
      setServerIssues([]);
    } catch (error) {
      setServerMessage(
        error instanceof Error ? error.message : "The saved field set could not be read.",
      );
    }
  }, [payload.urls.schema, pageSizes]);

  // -------------------------------------------------------------------- keyboard shortcuts

  useEffect(() => {
    function onKeyDown(event: KeyboardEvent): void {
      const meta = event.metaKey || event.ctrlKey;

      if (!meta) {
        return;
      }

      const key = event.key.toLowerCase();

      if (key === "s") {
        event.preventDefault();

        if (!readOnly && dirty && !saving) {
          void persist(false);
        }

        return;
      }

      // Undo and redo inside a text control belong to the text control.
      if (isTextEntry(event.target)) {
        return;
      }

      if (key === "z" && !event.shiftKey) {
        event.preventDefault();
        dispatch({ type: "undo" });
      } else if ((key === "z" && event.shiftKey) || key === "y") {
        event.preventDefault();
        dispatch({ type: "redo" });
      }
    }

    window.addEventListener("keydown", onKeyDown);

    return () => window.removeEventListener("keydown", onKeyDown);
  }, [readOnly, dirty, saving, persist]);

  // ------------------------------------------------------------------------------- render

  const transforms = useMemo(
    () => pages.map((page) => new PageTransform(page, zoom)),
    [pages, zoom],
  );

  return (
    <div className="flex flex-col gap-3">
      <header className="flex flex-wrap items-center gap-2">
        <h1 className="text-xl font-semibold tracking-tight">
          <a className="underline-offset-4 hover:underline" href={payload.urls.template}>
            {payload.template.name}
          </a>{" "}
          <span className="text-muted-foreground font-normal">v{payload.version.number}</span>
        </h1>

        <Badge variant={payload.version.status === "published" ? "secondary" : "outline"}>
          {payload.version.status}
        </Badge>

        <span className="text-muted-foreground text-sm">{payload.document.title}</span>
      </header>

      {readOnly ? (
        <Alert>
          <AlertTitle>Read-only</AlertTitle>
          <AlertDescription>
            {payload.read_only_reason === "version_published"
              ? "This version is published and frozen. An edit after publishing is version " +
                `${payload.version.number + 1}: draft the next version from the template.`
              : "Your role in this workspace can read templates and change none of them. " +
                "Everything below is visible; nothing is editable."}
          </AlertDescription>
        </Alert>
      ) : null}

      {pdfError === null ? null : (
        <Alert variant="destructive">
          <AlertTitle>The document could not be displayed.</AlertTitle>
          <AlertDescription>
            {pdfError} Field coordinates below are still the stored ones and are safe to edit
            numerically or in the fields table.
          </AlertDescription>
        </Alert>
      )}

      {conflict === null ? null : (
        <Alert variant="destructive">
          <AlertTitle>Somebody else saved this version.</AlertTitle>
          <AlertDescription>
            <p>{conflict}</p>
            <div className="mt-2 flex gap-2">
              <Button type="button" size="sm" variant="outline" onClick={() => void reloadFromServer()}>
                Discard mine and reload theirs
              </Button>
              <Button type="button" size="sm" variant="destructive" onClick={() => void persist(true)}>
                Save mine anyway
              </Button>
            </div>
          </AlertDescription>
        </Alert>
      )}

      <EditorToolbar
        document={state.document}
        pageCount={pageCount}
        currentPage={currentPage}
        onGoToPage={goToPage}
        zoom={zoom}
        onZoomChange={(next) => {
          setFitWidth(false);
          setZoom(next);
        }}
        onFitWidth={() => {
          setFitWidth(true);
          applyFitWidth();
        }}
        fitWidth={fitWidth}
        canUndo={canUndo(state)}
        canRedo={canRedo(state)}
        onUndo={() => dispatch({ type: "undo" })}
        onRedo={() => dispatch({ type: "redo" })}
        readOnly={readOnly}
        dirty={dirty}
        saving={saving}
        onSave={() => void persist(false)}
        newFieldType={newFieldType}
        onNewFieldTypeChange={setNewFieldType}
        newFieldRecipient={newFieldRecipient}
        onNewFieldRecipientChange={setNewFieldRecipient}
        onAddField={() =>
          dispatch({
            type: "add_field",
            field: createField(state.document, newFieldType, newFieldRecipient, currentPage, {
              x: 72,
              y: 72,
            }),
          })
        }
      />

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_22rem]">
        <Tabs defaultValue="pages" className="min-w-0">
          <TabsList>
            <TabsTrigger value="pages">Pages</TabsTrigger>
            <TabsTrigger value="table">Fields table</TabsTrigger>
          </TabsList>

          <TabsContent value="pages">
            <div
              ref={surface}
              className="bg-muted/40 flex max-h-[75vh] flex-col items-center gap-6 overflow-auto rounded-md p-4"
            >
              {transforms.map((transform) => (
                <PageSurface
                  key={transform.geometry.page}
                  pageNumber={transform.geometry.page}
                  transform={transform}
                  pdf={pdf}
                  devicePixelRatio={devicePixelRatio}
                  fields={state.document.fields.filter(
                    (field) => field.page === transform.geometry.page,
                  )}
                  recipientNames={recipientNames}
                  colors={colors}
                  selectedFieldId={state.selectedFieldId}
                  readOnly={readOnly}
                  issueFieldIds={issueFieldIds}
                  onSelect={(fieldId) => dispatch({ type: "select", fieldId })}
                  onMove={(fieldId, x, y) =>
                    dispatch({
                      type: "move_field",
                      id: fieldId,
                      x,
                      y,
                      bounds: boundsOf(pageSizes, transform.geometry.page),
                    })
                  }
                  onResize={(fieldId, rect) =>
                    dispatch({
                      type: "resize_field",
                      id: fieldId,
                      rect,
                      bounds: boundsOf(pageSizes, transform.geometry.page),
                    })
                  }
                  onNudge={(fieldId, dx, dy) =>
                    dispatch({
                      type: "nudge_field",
                      id: fieldId,
                      dx,
                      dy,
                      bounds: boundsOf(pageSizes, transform.geometry.page),
                    })
                  }
                  onDelete={(fieldId) => dispatch({ type: "delete_field", id: fieldId })}
                  onAddAt={(pageNumber, point) =>
                    dispatch({
                      type: "add_field",
                      field: createField(
                        state.document,
                        newFieldType,
                        newFieldRecipient,
                        pageNumber,
                        point,
                      ),
                    })
                  }
                  onVisible={onPageVisible}
                  registerPage={registerPage}
                />
              ))}

              {pageCount === 0 ? (
                <p className="text-muted-foreground text-sm">
                  This document's preflight report recorded no page geometry, so no page can be
                  drawn. Use the fields table, where coordinates are edited numerically.
                </p>
              ) : null}
            </div>
          </TabsContent>

          <TabsContent value="table">
            <FieldTable
              document={state.document}
              pageCount={pageCount}
              selectedFieldId={state.selectedFieldId}
              readOnly={readOnly}
              issueFieldIds={issueFieldIds}
              onSelect={(fieldId) => dispatch({ type: "select", fieldId })}
              onPatch={(fieldId, patch) => dispatch({ type: "update_field", id: fieldId, patch })}
              onDuplicate={(fieldId) => dispatch({ type: "duplicate_field", id: fieldId })}
              onDelete={(fieldId) => dispatch({ type: "delete_field", id: fieldId })}
            />
          </TabsContent>
        </Tabs>

        <aside className="flex min-w-0 flex-col gap-6">
          <FieldInspector
            document={state.document}
            field={selectedField}
            pageCount={pageCount}
            readOnly={readOnly}
            variables={payload.variables}
            onPatch={(fieldId, patch) => dispatch({ type: "update_field", id: fieldId, patch })}
            onDuplicate={(fieldId) => dispatch({ type: "duplicate_field", id: fieldId })}
            onDelete={(fieldId) => dispatch({ type: "delete_field", id: fieldId })}
          />

          <RecipientLegend
            document={state.document}
            colors={colors}
            fieldCounts={fieldCounts}
          />

          <ValidationPanel
            issues={issues}
            serverIssues={serverIssues}
            serverMessage={serverMessage}
            prefillChecked={payload.variables.length > 0}
            onSelectPath={(path) => {
              const id = fieldIdForPath(state.document, path);

              if (id !== null) {
                dispatch({ type: "select", fieldId: id });
              }
            }}
          />

          <ImportExportPanel
            document={state.document}
            readOnly={readOnly}
            pageSizes={pageSizes}
            exportName={`${payload.template.name}-v${payload.version.number}-fields`.replace(
              /[^A-Za-z0-9._-]+/g,
              "-",
            )}
            onImport={(document) => dispatch({ type: "import_document", document })}
          />
        </aside>
      </div>
    </div>
  );
}

/** The page size for a page number, or undefined when the geometry is not known. */
function boundsOf(pageSizes: PageSize[], pageNumber: number): PageSize | undefined {
  return pageSizes[pageNumber - 1];
}

/**
 * The field an RFC 6901 pointer points into, or null.
 *
 * Pointers into a field look like `/fields/3/rect/width`; everything else — `/recipients/0`,
 * `/signing_order`, the empty pointer — belongs to the document rather than to a field and
 * selects nothing.
 */
export function fieldIdForPath(document: FieldSchemaDocument, path: string): string | null {
  const match = /^\/fields\/(\d+)(\/|$)/.exec(path);

  if (match === null) {
    return null;
  }

  return document.fields[Number(match[1])]?.id ?? null;
}

function isTextEntry(target: EventTarget | null): boolean {
  if (!(target instanceof HTMLElement)) {
    return false;
  }

  return (
    target instanceof HTMLInputElement ||
    target instanceof HTMLTextAreaElement ||
    target.isContentEditable
  );
}
