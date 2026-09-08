/**
 * The editor's two network calls: re-read a version, and PATCH its field schema.
 *
 * Written against `fetch` directly rather than `@/fetchWrapper`, and the reason is the whole
 * point of the save path: `fetchWrapper` collapses a failed response to `data.message` and
 * throws the string away from the body. The template endpoint answers a rejected field set
 * with a **structured** 422 — every problem at once, each with an RFC 6901 JSON Pointer and a
 * stable code (`docs/preparation/templates.md`) — and the editor exists to show all of them,
 * annotated onto the offending fields. A single "invalid document" string is exactly the
 * thing that list was designed to replace.
 *
 * Server messages are surfaced **verbatim**. The editor never rewrites, summarises, or
 * re-derives them: the codes are API surface, and a friendlier local paraphrase is a second
 * error vocabulary that drifts from the one the API returns.
 */

import type { FieldSchemaDocument } from "@/schema/fieldSchema";

/** One problem, exactly as the server reported it. Not narrowed to the client's own code union. */
export interface ServerIssue {
  path: string;
  code: string;
  message: string;
}

export interface VersionState {
  status: string;
  fieldSchemaSha256: string;
}

export type SaveOutcome =
  | { status: "saved"; version: VersionState }
  | { status: "rejected"; message: string; issues: ServerIssue[]; errors: Record<string, string[]> }
  | { status: "conflict"; message: string; code: string }
  | { status: "failed"; message: string };

/**
 * Re-read the version's digest.
 *
 * The optimistic lock: the editor loaded a document with a known
 * `field_schema_sha256`, and if the stored one has moved since, somebody else saved in the
 * meantime and a blind PATCH would overwrite their work. There is no `If-Match` on this
 * endpoint, so the check is a read immediately before the write — a narrow race remains and
 * the editor says so rather than claiming a lock it does not hold.
 */
export async function readVersion(url: string): Promise<VersionState | null> {
  const response = await fetch(url, {
    method: "GET",
    credentials: "include",
    headers: { Accept: "application/json" },
  });

  if (!response.ok) {
    return null;
  }

  const body: unknown = await response.json().catch(() => null);

  if (!isRecord(body)) {
    return null;
  }

  return {
    status: typeof body["status"] === "string" ? body["status"] : "",
    fieldSchemaSha256:
      typeof body["field_schema_sha256"] === "string" ? body["field_schema_sha256"] : "",
  };
}

export async function saveFieldSchema(
  url: string,
  csrfToken: string,
  document: FieldSchemaDocument,
): Promise<SaveOutcome> {
  let response: Response;

  try {
    response = await fetch(url, {
      method: "PATCH",
      credentials: "include",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
        "X-CSRF-TOKEN": csrfToken,
      },
      body: JSON.stringify({ field_schema: document }),
    });
  } catch (error) {
    return { status: "failed", message: describeNetworkError(error) };
  }

  const body: unknown = await response.json().catch(() => null);

  if (response.ok) {
    return {
      status: "saved",
      version: {
        status: isRecord(body) && typeof body["status"] === "string" ? body["status"] : "",
        fieldSchemaSha256:
          isRecord(body) && typeof body["field_schema_sha256"] === "string"
            ? body["field_schema_sha256"]
            : "",
      },
    };
  }

  const message = isRecord(body) && typeof body["message"] === "string"
    ? body["message"]
    : `${response.status} ${response.statusText}`;

  if (response.status === 409) {
    return {
      status: "conflict",
      message,
      code: isRecord(body) && typeof body["code"] === "string" ? body["code"] : "conflict",
    };
  }

  if (response.status === 422) {
    return {
      status: "rejected",
      message,
      issues: readIssues(body),
      errors: readErrors(body),
    };
  }

  return { status: "failed", message };
}

function readIssues(body: unknown): ServerIssue[] {
  if (!isRecord(body) || !Array.isArray(body["field_schema_errors"])) {
    return [];
  }

  return body["field_schema_errors"].flatMap((entry: unknown): ServerIssue[] => {
    if (!isRecord(entry)) {
      return [];
    }

    return [
      {
        path: typeof entry["path"] === "string" ? entry["path"] : "",
        code: typeof entry["code"] === "string" ? entry["code"] : "",
        message: typeof entry["message"] === "string" ? entry["message"] : "",
      },
    ];
  });
}

function readErrors(body: unknown): Record<string, string[]> {
  if (!isRecord(body) || !isRecord(body["errors"])) {
    return {};
  }

  const errors: Record<string, string[]> = {};

  for (const [key, value] of Object.entries(body["errors"])) {
    errors[key] = Array.isArray(value) ? value.map((entry) => String(entry)) : [String(value)];
  }

  return errors;
}

function describeNetworkError(error: unknown): string {
  return error instanceof Error
    ? `The save request did not reach the server: ${error.message}`
    : "The save request did not reach the server.";
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}
