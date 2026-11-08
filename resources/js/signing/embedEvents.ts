/**
 * The signing page's progress announcements to a page that embeds or opened it (issue #168).
 *
 * The contract is `docs/signing/embedded-events.md`. In short:
 *
 * - Every message is `{ source: "esign-signing", version: 1, type, timestamp, ... }`.
 * - It is posted to the framing parent, or in a popup to the opener, **once per allowed
 *   origin** with that origin as the `targetOrigin` — never `"*"` — so only a page actually
 *   served from an origin the deployment allowlisted can read it.
 * - The allowlist comes from the server in `body[data-signing-events]`. No attribute, which is
 *   the default, means nothing is ever posted.
 * - Messages carry public identifiers and counts, never an email address or a field value.
 *
 * Announcements are a convenience for the embedding page's UI. They are not evidence and not
 * the durable signal: `signing_request.recipient.signed` and the completion webhook are.
 */

export const SIGNING_EVENT_SOURCE = "esign-signing";
export const SIGNING_EVENT_VERSION = 1;

export type SigningEventType =
  | "signing.loaded"
  | "signing.started"
  | "signing.completed"
  | "signing.declined"
  | "signing.error";

/** Lowercase, like every other code this application emits. */
export type SigningErrorCode =
  | "link_unusable"
  | "no_session"
  | "rate_limited"
  | "decline_not_allowed"
  | "review_stale"
  | "save_failed";

export interface SigningEventDetail {
  type: SigningEventType;
  envelope_id?: string;
  recipient_id?: string;
  total_fields?: number;
  code?: SigningErrorCode;
}

export interface SigningEventMessage extends SigningEventDetail {
  source: typeof SIGNING_EVENT_SOURCE;
  version: typeof SIGNING_EVENT_VERSION;
  timestamp: string;
}

export interface EmbedConfig {
  origins: string[];
  /** The event the page itself stands for — completed, declined, or a refusal — if any. */
  event: SigningEventDetail | null;
}

/** Read the server's allowlist and page event, or null when nothing may be announced. */
export function readEmbedConfig(doc: Document = document): EmbedConfig | null {
  const raw = doc.body?.dataset.signingEvents;

  if (!raw) {
    return null;
  }

  try {
    const parsed: unknown = JSON.parse(raw);

    if (typeof parsed !== "object" || parsed === null) {
      return null;
    }

    const candidate = parsed as { origins?: unknown; event?: unknown };
    const origins = Array.isArray(candidate.origins)
      ? candidate.origins.filter((origin): origin is string => typeof origin === "string" && origin !== "*")
      : [];

    if (origins.length === 0) {
      return null;
    }

    const event =
      typeof candidate.event === "object" && candidate.event !== null
        ? (candidate.event as SigningEventDetail)
        : null;

    return { origins, event };
  } catch {
    return null;
  }
}

/** The window to announce to: the framing parent, else the opener, else nobody. */
export function announcementTarget(win: Window = window): Window | null {
  if (win.parent !== win) {
    return win.parent;
  }

  return win.opener instanceof Object ? (win.opener as Window) : null;
}

/**
 * Post one event to every allowed origin. Returns how many posts were attempted, which is
 * zero whenever there is no allowlist or nobody to tell.
 */
export function postSigningEvent(
  detail: SigningEventDetail,
  config: EmbedConfig | null = readEmbedConfig(),
  target: Window | null = announcementTarget(),
  now: () => Date = () => new Date(),
): number {
  if (config === null || target === null) {
    return 0;
  }

  const message: SigningEventMessage = {
    ...detail,
    source: SIGNING_EVENT_SOURCE,
    version: SIGNING_EVENT_VERSION,
    timestamp: now().toISOString(),
  };

  let posted = 0;

  for (const origin of config.origins) {
    try {
      target.postMessage(message, origin);
      posted += 1;
    } catch {
      // A target that has navigated away or closed. Announcements are best-effort.
    }
  }

  return posted;
}
