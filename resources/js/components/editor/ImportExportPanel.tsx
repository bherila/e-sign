import { DownloadIcon, UploadIcon } from "lucide-react";
import { useRef, useState } from "react";

import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import {
  describeIssue,
  type FieldSchemaDocument,
  FieldSchemaError,
  type PageSize,
  parseFieldSchema,
  serializeFieldSchema,
  type ValidationIssue,
} from "@/schema/fieldSchema";

/**
 * JSON in and JSON out, through the same importer the server uses.
 *
 * Import goes through `parseFieldSchema`, never through `JSON.parse` and a cast. That is what
 * makes "editor -> JSON -> editor without drift" true rather than hoped for: the importer
 * canonicalises on the way in, so a document pasted in a different property order, with
 * defaults omitted, or with coordinates finer than a thousandth of a point, becomes exactly the
 * document the server would have stored. It also fails closed and reports **every** problem at
 * once, which is the difference between "your JSON is wrong" and a list of what to fix.
 *
 * Export is `serializeFieldSchema`, which is byte-identical to the server's
 * `FieldSchemaDocument::canonicalJson()`. Downloading here and downloading the version's
 * `schema.json` therefore produce the same bytes for the same document — which is what makes
 * the export usable for diffing two versions or checking a digest.
 *
 * There is deliberately no "import anyway" escape hatch. A partial import is a document with
 * fields nobody was asked to sign.
 */
export interface ImportExportPanelProps {
  document: FieldSchemaDocument;
  readOnly: boolean;
  /** Displayed page sizes, so an import is checked against the real pages, not just the shape. */
  pageSizes: PageSize[];
  /** Filename stem for the download. */
  exportName: string;
  onImport: (document: FieldSchemaDocument) => void;
}

export function ImportExportPanel({
  document,
  readOnly,
  pageSizes,
  exportName,
  onImport,
}: ImportExportPanelProps) {
  const [text, setText] = useState("");
  const [issues, setIssues] = useState<ValidationIssue[]>([]);
  const [message, setMessage] = useState<string | null>(null);
  const fileInput = useRef<HTMLInputElement | null>(null);

  function attempt(raw: string): void {
    setText(raw);

    try {
      const imported = parseFieldSchema(raw, { pageSizes });
      setIssues([]);
      setMessage(`Imported ${imported.fields.length} fields. Not saved yet.`);
      onImport(imported);
    } catch (error) {
      setMessage(null);
      setIssues(error instanceof FieldSchemaError ? error.issues : []);

      if (!(error instanceof FieldSchemaError)) {
        setMessage(error instanceof Error ? error.message : "The document could not be read.");
      }
    }
  }

  function download(): void {
    const blob = new Blob([serializeFieldSchema(document)], { type: "application/json" });
    const url = URL.createObjectURL(blob);
    const anchor = window.document.createElement("a");

    anchor.href = url;
    anchor.download = `${exportName}.json`;
    anchor.rel = "noopener";
    window.document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    URL.revokeObjectURL(url);
  }

  return (
    <section aria-labelledby="editor-import-export" className="flex flex-col gap-2">
      <h2 id="editor-import-export" className="text-sm font-semibold">
        JSON
      </h2>

      <div className="flex flex-wrap items-center gap-2">
        <Button type="button" variant="outline" size="sm" onClick={download}>
          <DownloadIcon aria-hidden="true" />
          Export canonical JSON
        </Button>

        <Button
          type="button"
          variant="outline"
          size="sm"
          disabled={readOnly}
          onClick={() => fileInput.current?.click()}
        >
          <UploadIcon aria-hidden="true" />
          Import a file
        </Button>

        <input
          ref={fileInput}
          type="file"
          accept="application/json,.json"
          className="sr-only"
          aria-label="Field schema JSON file"
          onChange={(event) => {
            const file = event.target.files?.[0];

            if (file === undefined) {
              return;
            }

            void file.text().then(attempt);
            event.target.value = "";
          }}
        />
      </div>

      <div className="flex flex-col gap-1">
        <Label htmlFor="editor-import-json" className="text-xs text-muted-foreground">
          Or paste a field schema document
        </Label>
        <Textarea
          id="editor-import-json"
          value={text}
          disabled={readOnly}
          spellCheck={false}
          rows={5}
          className="font-mono text-xs"
          placeholder='{"schema_version":"1.0", …}'
          onChange={(event) => setText(event.target.value)}
        />
        <Button
          type="button"
          variant="outline"
          size="sm"
          disabled={readOnly || text.trim() === ""}
          onClick={() => attempt(text)}
        >
          Import pasted JSON
        </Button>
      </div>

      {message === null ? null : (
        <p role="status" aria-live="polite" className="text-xs">
          {message}
        </p>
      )}

      {issues.length === 0 ? null : (
        <div role="status" aria-live="polite" className="flex flex-col gap-1">
          <p className="text-destructive text-xs font-medium">
            Nothing was imported. {issues.length} {issues.length === 1 ? "problem" : "problems"}:
          </p>
          <ul className="text-muted-foreground flex flex-col gap-0.5 text-xs">
            {issues.map((issue, index) => (
              <li key={`${issue.path}-${issue.code}-${index}`} className="font-mono">
                {describeIssue(issue)}
              </li>
            ))}
          </ul>
        </div>
      )}
    </section>
  );
}
