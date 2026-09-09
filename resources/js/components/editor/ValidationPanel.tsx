import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";
import { FIELD_SCHEMA_VERSION, type ValidationIssue } from "@/schema/fieldSchema";

import type { ServerIssue } from "./api";

/**
 * Every problem with the document, at once.
 *
 * The schema validator reports the whole list rather than the first failure, each entry
 * carrying an RFC 6901 JSON Pointer and a stable code, precisely so an editor can annotate
 * every offending field in one pass (docs/preparation/field-schema.md). This panel is that
 * pass. Selecting an entry moves the selection to the field it points at.
 *
 * Two lists, kept apart on purpose:
 *
 *  - **Local** — what `parseFieldSchema` says about the document in the browser right now.
 *  - **From the server** — what the last save was refused for, shown **verbatim**, message and
 *    code exactly as sent. The server's list is authoritative: it runs the checks the browser
 *    cannot (page geometry it did not receive, prefill variables it does not have), and a
 *    friendlier local paraphrase would be a second error vocabulary that drifts from the API's.
 *
 * The count is announced through a polite live region, so somebody who cannot see the panel
 * change is still told that the document stopped validating after their last edit.
 */
export interface ValidationPanelProps {
  issues: ValidationIssue[];
  serverIssues: ServerIssue[];
  serverMessage: string | null;
  /** False when no variable list was supplied, in which case prefills were not checked at all. */
  prefillChecked: boolean;
  onSelectPath: (path: string) => void;
}

export function ValidationPanel({
  issues,
  serverIssues,
  serverMessage,
  prefillChecked,
  onSelectPath,
}: ValidationPanelProps) {
  const total = issues.length;

  return (
    <section aria-labelledby="editor-validation" className="flex flex-col gap-2">
      <h2 id="editor-validation" className="text-sm font-semibold">
        Validation
      </h2>

      {/*
        The live region is separate from the list and holds only the summary. Announcing the
        whole list on every keystroke would talk over the person editing.
      */}
      <p
        role="status"
        aria-live="polite"
        className={cn("text-sm", total === 0 ? "text-muted-foreground" : "text-destructive")}
      >
        {total === 0
          ? `The field set is valid against schema ${FIELD_SCHEMA_VERSION}.`
          : `${total} ${total === 1 ? "problem" : "problems"} in the field set.`}
      </p>

      {prefillChecked ? null : (
        <p className="text-muted-foreground text-xs">
          Prefill variables were not checked: this deployment declares no variable list, and a
          template has no sending context. An omitted check is not a passed one — the send-time
          gate still runs it.
        </p>
      )}

      {total === 0 ? null : (
        <ul className="flex flex-col gap-1">
          {issues.map((issue, index) => (
            <li key={`${issue.path}-${issue.code}-${index}`}>
              <Button
                type="button"
                variant="ghost"
                className="h-auto w-full justify-start px-2 py-1 text-left text-xs whitespace-normal"
                onClick={() => onSelectPath(issue.path)}
              >
                <span className="flex flex-col gap-0.5">
                  <span className="font-mono">
                    {issue.path === "" ? "(document)" : issue.path} [{issue.code}]
                  </span>
                  <span className="text-muted-foreground">{issue.message}</span>
                </span>
              </Button>
            </li>
          ))}
        </ul>
      )}

      {serverMessage === null ? null : (
        <div className="border-destructive/40 flex flex-col gap-1 rounded-md border p-2">
          <p className="text-destructive text-xs font-medium">{serverMessage}</p>

          {serverIssues.length === 0 ? null : (
            <ul className="flex flex-col gap-1">
              {serverIssues.map((issue, index) => (
                <li key={`${issue.path}-${issue.code}-${index}`}>
                  <Button
                    type="button"
                    variant="ghost"
                    className="h-auto w-full justify-start px-2 py-1 text-left text-xs whitespace-normal"
                    onClick={() => onSelectPath(issue.path)}
                  >
                    <span className="flex flex-col gap-0.5">
                      <span className="font-mono">
                        {issue.path === "" ? "(document)" : issue.path} [{issue.code}]
                      </span>
                      <span className="text-muted-foreground">{issue.message}</span>
                    </span>
                  </Button>
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
    </section>
  );
}
