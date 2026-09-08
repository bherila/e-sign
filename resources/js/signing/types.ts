/**
 * The payload the Blade shell writes into `#signing-page[data-signing]`.
 *
 * Snake_case throughout, matching the field schema's own convention and the server's array
 * keys, so a value means the same thing on both sides of the attribute and nothing has to be
 * renamed on the way in. See `App\Http\Resources\Signing\SigningPagePayload`, which is the
 * only thing that builds one.
 */

import type { PageGeometryPayload } from "@/components/editor/PageTransform";

export interface SigningEnvelope {
  id: string;
  title: string;
  state: string;
  /** The workspace that sent it, for display. Never an address. */
  sender: string;
  expires_at: string | null;
}

export interface SigningRecipient {
  id: string;
  name: string;
  schema_recipient_id: string;
  state: string;
}

/** Another party on the agreement: name and role only, deliberately no address. */
export interface SigningParty {
  schema_recipient_id: string;
  name: string;
  role: string | null;
  state: string;
  is_you: boolean;
}

/**
 * The two values an acceptance has to quote back, exactly as displayed.
 *
 * docs/ARCHITECTURE.md invariant 2. They are refreshed by every successful values save, and
 * the accept form submits whatever is current here; if the server's have moved in between,
 * the acceptance is refused as stale rather than bound to text nobody saw.
 */
export interface ReviewedState {
  material_values_sha256: string;
  envelope_version: number;
}

export interface ConsentPayload {
  /** The version snapshotted on the envelope; what the attestation will record. */
  recorded_version: string;
  /** The version the deployment says the text on disk is. */
  text_version: string;
  matches_recorded_version: boolean;
  /** Rendered server-side from a repository file. */
  html: string;
}

export interface CaptureLimits {
  max_signature_image_bytes: number;
  max_signature_image_width: number;
  max_signature_image_height: number;
}

export interface SigningUrls {
  document: string;
  values: string;
  accept: string;
  decline: string;
  reload: string;
}

/** A stored field value. `null` means the field has no value yet. */
export type FieldValue = string | boolean | null;

export interface SigningPayload {
  envelope: SigningEnvelope;
  recipient: SigningRecipient;
  parties: SigningParty[];
  document: { title: string; page_count: number | null };
  pages: PageGeometryPayload[];
  /** The envelope's copied field schema. Parsed through `parseFieldSchema` on mount. */
  field_schema: unknown;
  /** Fields this recipient may write. A rendering hint; the server enforces ownership. */
  own_field_ids: string[];
  /** Signature and initials fields this recipient must complete before they can sign. */
  required_signature_field_ids: string[];
  /** Filled by the service from the attestation. Shown, never editable. */
  service_supplied_field_ids: string[];
  values: Record<string, FieldValue>;
  reviewed: ReviewedState;
  consent: ConsentPayload;
  limits: CaptureLimits;
  urls: SigningUrls;
  csrf_token: string;
}
