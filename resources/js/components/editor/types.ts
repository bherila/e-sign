/**
 * The payload the Blade shell writes into `#field-editor[data-editor]`.
 *
 * Snake_case throughout, matching the field schema's own convention and the server's array
 * keys, so a value means the same thing on both sides of the attribute and nothing has to be
 * renamed on the way in. See `App\Http\Controllers\Editor\FieldEditorController`.
 */

import type { PageGeometryPayload } from "./PageTransform";

export interface EditorUrls {
  /** Inline PDF bytes of the review revision this version snapshots. */
  document_view: string;
  /** The version representation, re-read before a save to detect a concurrent edit. */
  version: string;
  /** The canonical field-schema bytes, used by the JSON export. */
  schema: string;
  /** PATCH target. The only write path; there is no editor-specific write endpoint. */
  save: string;
  template: string;
}

export interface EditorPayload {
  workspace: { id: string; name: string };
  template: { id: string; name: string };
  version: {
    id: string;
    number: number;
    status: "draft" | "published";
    published_at: string | null;
  };
  document: {
    id: string;
    title: string;
    revision_id: string;
    page_count: number | null;
  };
  urls: EditorUrls;
  /** The session's CSRF token, for the PATCH. Same value as the layout's meta tag. */
  csrf_token: string;
  read_only: boolean;
  /** `version_published`, `insufficient_role`, or null when the page is editable. */
  read_only_reason: string | null;
  /** The version's field set, already canonical. Parsed through `parseFieldSchema` on mount. */
  field_schema: unknown;
  field_schema_sha256: string;
  pages: PageGeometryPayload[];
  /**
   * Prefill variables the sending context can resolve. Empty means "not known here", which
   * makes the editor skip the resolvability check and say so — never treat it as "none
   * resolve".
   */
  variables: string[];
}
