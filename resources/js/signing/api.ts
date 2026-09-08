/**
 * The one network call the signing island makes.
 *
 * Accepting and declining are ordinary form posts that navigate, because their outcome is a
 * page somebody has to be able to reload, print, and bookmark, and a decision that depends on
 * a fetch completing is a decision that can be lost between the click and the record. Saving
 * values is different: it happens repeatedly while the signer works and must not navigate.
 *
 * `credentials: "same-origin"` is stated rather than left to the default so the `esign_signing`
 * cookie travels, and the CSRF token comes from the payload the server rendered rather than
 * from a meta tag lookup that could find a stale one.
 */

import type { FieldValue, ReviewedState } from "./types";

export interface SaveValuesResponse {
  field_ids: string[];
  reviewed: ReviewedState;
}

export class SigningApiError extends Error {
  constructor(
    message: string,
    /** Stable server-side reason, where there was one. */
    public readonly reason: string | null,
    public readonly status: number,
  ) {
    super(message);
    this.name = "SigningApiError";
  }
}

interface ErrorBody {
  error?: unknown;
  message?: unknown;
  errors?: unknown;
}

export async function saveValues(
  url: string,
  csrfToken: string,
  values: Record<string, FieldValue>,
): Promise<SaveValuesResponse> {
  let response: Response;

  try {
    response = await fetch(url, {
      method: "POST",
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
        "X-CSRF-TOKEN": csrfToken,
        "X-Requested-With": "XMLHttpRequest",
      },
      body: JSON.stringify({ values }),
    });
  } catch {
    // A network failure is not a server refusal, and telling the signer their value was
    // rejected when it never arrived would send them looking for a problem with the value.
    throw new SigningApiError(
      "Your changes could not be sent. Check your connection and try again; nothing has been lost.",
      null,
      0,
    );
  }

  if (response.status === 419) {
    // The application session expired underneath the page. Re-submitting will not help.
    throw new SigningApiError(
      "This page has been open too long to save safely. Reload it and continue; anything already saved is still there.",
      "csrf_expired",
      419,
    );
  }

  const body: unknown = await response.json().catch(() => null);

  if (!response.ok) {
    throw new SigningApiError(messageFrom(body), reasonFrom(body), response.status);
  }

  if (!isSaveResponse(body)) {
    throw new SigningApiError("The server's reply could not be understood.", null, response.status);
  }

  return body;
}

function reasonFrom(body: unknown): string | null {
  if (typeof body === "object" && body !== null) {
    const error = (body as ErrorBody).error;

    if (typeof error === "string") {
      return error;
    }
  }

  return null;
}

function messageFrom(body: unknown): string {
  if (typeof body === "object" && body !== null) {
    const candidate = body as ErrorBody;

    if (typeof candidate.message === "string" && candidate.message !== "") {
      return candidate.message;
    }

    // Laravel's validation shape: { errors: { "values.foo": ["..."] } }.
    if (typeof candidate.errors === "object" && candidate.errors !== null) {
      for (const messages of Object.values(candidate.errors as Record<string, unknown>)) {
        if (Array.isArray(messages) && typeof messages[0] === "string") {
          return messages[0];
        }
      }
    }
  }

  return "Your changes could not be saved.";
}

function isSaveResponse(body: unknown): body is SaveValuesResponse {
  if (typeof body !== "object" || body === null) {
    return false;
  }

  const candidate = body as Partial<SaveValuesResponse>;

  return (
    Array.isArray(candidate.field_ids) &&
    typeof candidate.reviewed === "object" &&
    candidate.reviewed !== null &&
    typeof candidate.reviewed.material_values_sha256 === "string" &&
    typeof candidate.reviewed.envelope_version === "number"
  );
}
