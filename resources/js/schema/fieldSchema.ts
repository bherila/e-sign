/**
 * Native field definition schema — the editor's half of the contract.
 *
 * The contract itself is `resources/schema/field-schema-1.0.json`; the server half is
 * `app/Domain/Preparation/Schema`. The three are kept in step by tests, not by convention:
 * `fieldSchemaContract.test.ts` validates the shared fixture against that JSON Schema with ajv
 * and pins these types' property lists and codes against it, and the PHP suite pins the same
 * file from the other side.
 *
 * Types mirror the JSON exactly, snake_case included, so a parsed document is the document.
 * There is no camelCase editor shape to translate to and from, because that translation is
 * where a `read_only` quietly becomes a `readOnly` nobody reads.
 *
 * Rectangles are plain numbers in the native coordinate space (pt, top-left origin, CropBox,
 * displayed rotation, 1-based pages). Converting them to PDF user space is the server's job.
 * Never infer points versus percent from a number's magnitude.
 */

/**
 * The schema version this build implements and writes.
 *
 * 1.1 adds the optional anchor members `placement`, `required` and `tolerance`, and the
 * service-written `resolved` receipt. They are additive and optional, which is the case the
 * version policy says bumps the minor: a 1.0 reader validating with `additionalProperties: false`
 * must not be handed a document that still calls itself 1.0 and carries members its contract does
 * not declare.
 */
export const FIELD_SCHEMA_VERSION = "1.1";

/** Every minor this build can read, oldest first. Each has its own published contract file. */
export const SUPPORTED_FIELD_SCHEMA_VERSIONS = ["1.0", "1.1"] as const;

export type FieldSchemaVersion = (typeof SUPPORTED_FIELD_SCHEMA_VERSIONS)[number];

/**
 * Field types version 1.0 implements, in schema declaration order.
 *
 * A type outside this list is rejected on import, never dropped: a silently ignored field is a
 * field nobody was asked to complete. Adding one is an additive change that bumps the minor
 * version and is declared as a capability.
 */
export const FIELD_TYPES = [
  "signature",
  "initials",
  "text",
  "name",
  "company",
  "title",
  "agreement_date",
  "signing_date",
  "checkbox",
] as const;

export type FieldType = (typeof FIELD_TYPES)[number];

/** The one coordinate space version 1.0 implements. Every value is fixed. */
export const NATIVE_COORDINATE_SPACE = {
  unit: "pt",
  origin: "top-left",
  page_box: "crop",
  rotation: "displayed",
  page_index_base: 1,
} as const;

export type CoordinateSpace = typeof NATIVE_COORDINATE_SPACE;

/** Decimal places retained on a coordinate; 0.001 pt is far below what a drag can express. */
export const CANONICAL_DECIMALS = 3;

/**
 * Largest `anchor.tolerance` a document may state, in points: PDF's own maximum page side.
 *
 * Mirrors `AnchorPlacement::MAX_TOLERANCE`. The bound exists to keep the canonical form **total**,
 * not because 200 inches of slack would be an unreasonable cross-check. Above roughly 1e17 this
 * runtime writes `100000000000000000000` where PHP writes `1.0e+20`, so the same document would
 * canonicalise to two different digests — and `field_schema_sha256` is what every attestation
 * binds. A tolerance is a distance between two positions on one page, so PDF's 14400 pt limit is
 * the largest value that can mean anything, and it sits five orders of magnitude below the
 * disagreement.
 */
export const ANCHOR_TOLERANCE_MAX = 14400;

/**
 * Largest magnitude any `measured_rect` component may have, in points: PDF's maximum page side.
 *
 * Mirrors `MeasuredRect::MAX_MAGNITUDE`, and exists for the same reason as
 * {@link ANCHOR_TOLERANCE_MAX} rather than for any reason about measurement — see
 * `docs/preparation/field-schema.md`, "Why every number in this schema is bounded". Applied to
 * `x` and `y` in both directions: a measurement may sit slightly off the page, but not a page
 * away from it.
 */
export const MEASURED_RECT_MAX_MAGNITUDE = 14400;

/**
 * Largest integer this schema admits: 2^53 - 1, the largest both implementations agree on.
 *
 * Mirrors `CanonicalNumber::MAX_INTEGER`. Every integer property — a page number, an occurrence
 * index — is bounded by it so a document means the same thing on both sides. PHP's integers run
 * to 2^63 - 1 and this runtime stops being exact at 2^53, so between the two a value is a whole
 * number here and unrepresentable there: the editor would accept a document the API refuses, and
 * an integration would meet that as a 422 after being told its document was fine.
 *
 * `Number.MAX_SAFE_INTEGER` is the line because it is the largest integer this runtime can hold
 * *and distinguish from its successor*.
 */
export const MAX_SCHEMA_INTEGER = Number.MAX_SAFE_INTEGER;

/** Slack when comparing a rounded coordinate against a page edge: one unit in the last place. */
export const CANONICAL_TOLERANCE = 0.001;

export interface Rect {
  x: number;
  y: number;
  width: number;
  height: number;
}

export interface Prefill {
  variable: string;
}

export interface AnchorOffset {
  dx: number;
  dy: number;
}

/**
 * Which match an anchor binds to: `"sole"` (the text must occur exactly once in scope) or a
 * 1-based index. Required, with no default — the resolver refuses a "first match wins" fallback,
 * because silently taking the first match moves a signature box the moment the contract text
 * changes. The resolver's `all` mode places one box per match, which a single field cannot
 * represent, so it is not a value here.
 */
export type AnchorOccurrence = "sole" | number;

/** Corner of the matched text box the offset is measured from. Nothing is inferred from its sign. */
export const ANCHOR_ORIGINS = ["top_left", "top_right", "bottom_left", "bottom_right"] as const;

export type AnchorOrigin = (typeof ANCHOR_ORIGINS)[number];

export const DEFAULT_ANCHOR_ORIGIN: AnchorOrigin = "top_left";

/**
 * Which of a field's two statements about position wins.
 *
 * Every field carries a rect, so a field that also carries an anchor holds two of them.
 * `"replace"` makes the anchor authoritative for x and y and keeps only the rect's size;
 * `"cross_check"` keeps the declared rect and requires the anchor to resolve within
 * `tolerance` points of it.
 *
 * Omitted means `"replace"`, which is not the kind of default `occurrence` refuses: it is the
 * only behaviour an anchor has ever had here, so it is what an already-written document meant.
 * Both defaults are omitted from the canonical form for the same reason — an anchor written
 * before these properties existed must still canonicalise to exactly its old bytes, because the
 * field-schema digest is what every attestation on an anchored agreement is bound to.
 */
export const ANCHOR_PLACEMENTS = ["replace", "cross_check"] as const;

export type AnchorPlacement = (typeof ANCHOR_PLACEMENTS)[number];

/** Whether an anchor's text must be present. Omitted means true; false needs an optional field. */
export const DEFAULT_ANCHOR_REQUIRED = true;

export const DEFAULT_ANCHOR_PLACEMENT: AnchorPlacement = "replace";

/**
 * A rectangle recording where something *was*, rather than where something goes.
 *
 * `x` and `y` may be negative and nothing is checked against the page: a run's nominal box is its
 * advance by the font's ascent plus descent, so a heading near the top of the page legitimately
 * starts above the CropBox edge.
 */
export interface MeasuredRect {
  x: number;
  y: number;
  width: number;
  height: number;
}

/** What resolution found, written by the service and never authored in the editor. */
export interface ResolvedAnchor {
  document_sha256: string;
  page: number;
  occurrence_index: number;
  anchor_rect: MeasuredRect;
  rect: Rect;
}

export interface Anchor {
  text: string;
  occurrence: AnchorOccurrence;
  placement?: AnchorPlacement;
  origin?: AnchorOrigin;
  offset?: AnchorOffset;
  required?: boolean;
  tolerance?: number;
  resolved?: ResolvedAnchor;
}

export interface Recipient {
  id: string;
  name: string;
  email: string;
  role?: string;
}

export interface FieldDefinition {
  id: string;
  recipient_id: string;
  type: FieldType;
  page: number;
  rect: Rect;
  required: boolean;
  read_only: boolean;
  label?: string;
  alias?: string;
  prefill?: Prefill;
  anchor?: Anchor;
}

export interface FieldSchemaDocument {
  schema_version: FieldSchemaVersion;
  document_id: string;
  coordinate_space: CoordinateSpace;
  recipients: Recipient[];
  signing_order: string[][];
  fields: FieldDefinition[];
}

/** Displayed size of one page, in the same unit and orientation as the coordinate space. */
export interface PageSize {
  width: number;
  height: number;
}

/**
 * Machine-readable rejection reasons. Identical to `App\Domain\Preparation\Schema\ValidationCode`;
 * a contract test asserts the two lists match.
 */
export const VALIDATION_CODES = [
  "missing_property",
  "unknown_property",
  "invalid_type",
  "invalid_format",
  "invalid_email",
  "empty_collection",
  "schema_version_unsupported",
  "unsupported_coordinate_space",
  "unsupported_field_type",
  "duplicate_id",
  "duplicate_alias",
  "unknown_recipient",
  "recipient_not_in_signing_order",
  "recipient_duplicated_in_signing_order",
  "page_out_of_range",
  "coordinate_not_finite",
  "coordinate_negative",
  "dimension_not_positive",
  "rect_out_of_page",
  "unresolved_prefill_variable",
  "coordinate_too_precise",
  "anchor_optional_on_required_field",
  "anchor_cross_check_failed",
  "anchor_not_found",
  "anchor_ambiguous",
  "anchor_occurrence_out_of_range",
  "anchor_text_unreadable",
  "anchor_resolved_off_page",
  "anchor_receipt_inconsistent",
  "anchor_resolution_unavailable",
] as const;

export type ValidationCode = (typeof VALIDATION_CODES)[number];

/** One reason a document was rejected, addressed with an RFC 6901 JSON Pointer. */
export interface ValidationIssue {
  path: string;
  code: ValidationCode;
  message: string;
}

/**
 * Context-dependent checks. Omitting either is not the same as passing it: the page count and
 * the page edges are only checked when the page sizes are known, and a prefill variable is only
 * checked for resolvability when the sending context's variables are known.
 */
export interface ValidationOptions {
  /** Displayed page sizes, page 1 first. */
  pageSizes?: PageSize[];
  /** Prefill variables the sending context can resolve. */
  variables?: string[];
}

/** Thrown by {@link parseFieldSchema}; carries every issue, not just the first. */
export class FieldSchemaError extends Error {
  public readonly issues: ValidationIssue[];

  constructor(issues: ValidationIssue[]) {
    const first = issues[0];
    super(
      `Invalid native field schema document: ${issues.length} ${issues.length === 1 ? "issue" : "issues"}. ` +
        `First: ${first ? describeIssue(first) : "(none reported)"}`,
    );
    this.name = "FieldSchemaError";
    this.issues = issues;
  }
}

export function describeIssue(issue: ValidationIssue): string {
  return `${issue.path === "" ? "(document)" : issue.path} [${issue.code}] ${issue.message}`;
}

const IDENTIFIER_PATTERN = /^[A-Za-z0-9][A-Za-z0-9._-]*$/;
const IDENTIFIER_MAX_LENGTH = 64;
const VARIABLE_PATTERN = /^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$/;
const VARIABLE_MAX_LENGTH = 128;
const EMAIL_PATTERN = /^[^@\s]+@[^@\s]+\.[^@\s]+$/;
const EMAIL_MAX_LENGTH = 320;
const NAME_MAX_LENGTH = 255;
const ROLE_MAX_LENGTH = 128;
const LABEL_MAX_LENGTH = 200;
const ANCHOR_TEXT_MAX_LENGTH = 255;
const ANCHOR_OCCURRENCE_SOLE = "sole";

/** Property lists, in canonical order. Exported so the contract tests can pin them. */
export const DOCUMENT_REQUIRED = [
  "schema_version",
  "document_id",
  "coordinate_space",
  "recipients",
  "signing_order",
  "fields",
] as const;
export const RECIPIENT_REQUIRED = ["id", "name", "email"] as const;
export const RECIPIENT_OPTIONAL = ["role"] as const;
export const FIELD_REQUIRED = ["id", "recipient_id", "type", "page", "rect"] as const;
export const FIELD_OPTIONAL = ["required", "read_only", "label", "alias", "prefill", "anchor"] as const;
export const RECT_REQUIRED = ["x", "y", "width", "height"] as const;
export const ANCHOR_REQUIRED = ["text", "occurrence"] as const;
export const ANCHOR_OPTIONAL = ["placement", "origin", "offset", "required", "tolerance", "resolved"] as const;
/** Anchor members that arrived after 1.0, and the minor that declares them. */
export const ANCHOR_MEMBERS_SINCE_1_1 = ["placement", "required", "tolerance", "resolved"] as const;

export const ANCHOR_MEMBERS_MINOR = 1;

/**
 * The first minor version that refuses an over-precise coordinate instead of rounding it.
 *
 * 1.0 rounds, as it always has. Refusing is a semantic tightening — a document 1.0 accepted stops
 * being accepted — and this schema's policy reserves those for a **major** bump. The price is that
 * a 1.0 document can still canonicalise two ways across the two implementations (issue #105); that
 * is a 2.0 question, not something a minor version fixes by tightening underneath its consumers.
 */
export const PRECISION_REFUSED_SINCE_MINOR = 1;

export const RESOLVED_ANCHOR_REQUIRED = [
  "document_sha256",
  "page",
  "occurrence_index",
  "anchor_rect",
  "rect",
] as const;
const DOCUMENT_SHA256_PATTERN = /^[0-9a-f]{64}$/;

/**
 * Round to the canonical precision, half away from zero.
 *
 * Rounds the *decimal* representation rather than `value * 1000`, which is what makes this agree
 * with PHP's `round($value, 3)`: `60.1235 * 1000` is 60123.499999999993 in binary floating point
 * and would round down, while the string exponent form parses to exactly 60123.5 and rounds up.
 */
/**
 * Whether a value is already canonical: at most {@link CANONICAL_DECIMALS} decimal places.
 *
 * The importer refuses a finer value rather than rounding it, and the difference matters more than
 * it looks. Rounding is a *transformation*, and the two implementations of this schema do not
 * agree about every transformation: `1.6484999999999999` rounds to 1.649 here and to 1.648 in PHP,
 * so the same submitted document would canonicalise to two byte strings and two
 * `field_schema_sha256` — the digest every attestation binds. Refusing means no accepted value is
 * ever transformed, so there is nothing to disagree about.
 *
 * Producers still round, and that is safe because it happens once, on one side, before the value
 * is part of a document: {@link roundCoordinate} is what the editor applies to what a drag
 * produced. What it then sends is taken literally.
 */
export function isCanonical(value: number): boolean {
  return Number.isFinite(value) && roundCoordinate(value) === value;
}

export function roundCoordinate(value: number): number {
  if (!Number.isFinite(value)) {
    return value;
  }

  // An integral value has no fractional part to round, whatever its magnitude, and shifting it
  // through a decimal string is where this function used to lose precision or produce NaN.
  if (Number.isInteger(value)) {
    return value;
  }

  const repr = value.toString();

  if (repr.includes("e") || repr.includes("E")) {
    // Exponential notation: far outside anything a page rectangle can hold, and the string
    // exponent trick below does not compose with an exponent already in the text.
    const scaled = value * 10 ** CANONICAL_DECIMALS;

    // A value large enough that scaling it overflows has no fractional part to round in the
    // first place, so rounding is the identity — which is also what PHP's `round()` returns for
    // it. Returning `Infinity` instead would serialise as `null` through `JSON.stringify`, so a
    // document the validator accepted would be exported as one its own importer refuses, and the
    // two projections would disagree about the same input.
    if (!Number.isFinite(scaled)) {
      return value;
    }

    return Math.round(scaled) / 10 ** CANONICAL_DECIMALS;
  }

  const shifted = Number(`${repr}e${CANONICAL_DECIMALS}`);

  if (!Number.isFinite(shifted)) {
    return value;
  }

  const rounded = shifted < 0 ? -Math.round(-shifted) : Math.round(shifted);
  const shiftedBack = Number(`${rounded}e-${CANONICAL_DECIMALS}`);

  // `rounded` can be large enough that *its* own `toString()` is exponential — 1e20 shifts to
  // 1e23 — and appending another exponent gives "1e+23e-3", which parses as NaN. That NaN then
  // serialises as `null`, so a value the validator accepted would be exported as a document its
  // own importer refuses. A value that big has no fractional part to round anyway, so falling
  // back to plain arithmetic is exact for it.
  return Number.isFinite(shiftedBack) ? shiftedBack : Math.round(value * 10 ** CANONICAL_DECIMALS) / 10 ** CANONICAL_DECIMALS;
}

/**
 * Validate a decoded document. Returns every issue, in document order, so the editor can
 * annotate all offending fields in one pass.
 */
export function validateFieldSchema(input: unknown, options: ValidationOptions = {}): ValidationIssue[] {
  const issues: ValidationIssue[] = [];

  if (!isObject(input)) {
    issues.push(issue("", "invalid_type", "Document must be a JSON object."));

    return issues;
  }

  checkObjectShape("", input, DOCUMENT_REQUIRED, [], issues);
  checkSchemaVersion(input, issues);

  if ("document_id" in input) {
    checkIdentifier("/document_id", "document_id", input["document_id"], issues);
  }

  checkCoordinateSpace(input, issues);

  const recipientIds = checkRecipients(input, issues);
  checkSigningOrder(input, recipientIds, issues);
  checkFields(input, recipientIds, options, issues);

  return issues;
}

/**
 * Import a document, from JSON text or an already-decoded value.
 *
 * Returns it in canonical form: defaults stated, coordinates rounded once. There is no partial
 * import — anything the schema does not allow throws with every issue attached.
 */
export function parseFieldSchema(input: unknown, options: ValidationOptions = {}): FieldSchemaDocument {
  let decoded: unknown = input;

  if (typeof input === "string") {
    try {
      decoded = JSON.parse(input);
    } catch (error) {
      throw new FieldSchemaError([
        issue("", "invalid_type", `Document is not valid JSON: ${(error as Error).message}`),
      ]);
    }
  }

  const issues = validateFieldSchema(decoded, options);

  if (issues.length > 0) {
    throw new FieldSchemaError(issues);
  }

  return canonicaliseDocument(decoded as FieldSchemaDocument);
}

/**
 * Export a document as deterministic JSON: canonical property order, canonical numbers, no
 * insignificant whitespace. Byte-identical to `FieldSchemaDocument::canonicalJson()` in PHP,
 * so the same document hashes the same on both sides.
 */
export function serializeFieldSchema(document: FieldSchemaDocument): string {
  return JSON.stringify(canonicaliseDocument(document));
}

/** The canonical object form: property order fixed, defaults stated, coordinates rounded. */
export function canonicaliseDocument(document: FieldSchemaDocument): FieldSchemaDocument {
  return {
    // The version the document arrived with, not the one this build writes: a 1.0 document that
    // uses none of the 1.1 members stays a 1.0 document, byte for byte, and so does its digest.
    // Only the server rewrites a document's version, and only when it rewrites its fields.
    schema_version: document.schema_version ?? FIELD_SCHEMA_VERSION,
    document_id: document.document_id,
    coordinate_space: {
      unit: NATIVE_COORDINATE_SPACE.unit,
      origin: NATIVE_COORDINATE_SPACE.origin,
      page_box: NATIVE_COORDINATE_SPACE.page_box,
      rotation: NATIVE_COORDINATE_SPACE.rotation,
      page_index_base: NATIVE_COORDINATE_SPACE.page_index_base,
    },
    recipients: document.recipients.map(canonicaliseRecipient),
    signing_order: document.signing_order.map((stage) => [...stage]),
    fields: document.fields.map(canonicaliseField),
  };
}

function canonicaliseRecipient(recipient: Recipient): Recipient {
  const canonical: Recipient = {
    id: recipient.id,
    name: recipient.name,
    email: recipient.email,
  };

  if (recipient.role !== undefined) {
    canonical.role = recipient.role;
  }

  return canonical;
}

function canonicaliseField(field: FieldDefinition): FieldDefinition {
  const canonical: FieldDefinition = {
    id: field.id,
    recipient_id: field.recipient_id,
    type: field.type,
    page: field.page,
    rect: canonicaliseRect(field.rect),
    required: field.required ?? true,
    read_only: field.read_only ?? false,
  };

  if (field.label !== undefined) {
    canonical.label = field.label;
  }

  if (field.alias !== undefined) {
    canonical.alias = field.alias;
  }

  if (field.prefill !== undefined) {
    canonical.prefill = { variable: field.prefill.variable };
  }

  if (field.anchor !== undefined) {
    canonical.anchor = canonicaliseAnchor(field.anchor);
  }

  return canonical;
}

function canonicaliseRect(rect: Rect): Rect {
  return {
    x: roundCoordinate(rect.x),
    y: roundCoordinate(rect.y),
    width: roundCoordinate(rect.width),
    height: roundCoordinate(rect.height),
  };
}

/**
 * Canonical anchor order: the declared properties in schema order, with anything that equals its
 * default omitted — the anchor object's own long-standing convention, and here load-bearing: an
 * anchor written before `placement` and `required` existed must canonicalise to exactly its old
 * bytes, or the field-schema digest every attestation is bound to would move.
 */
function canonicaliseAnchor(anchor: Anchor): Anchor {
  const canonical: Anchor = {
    text: anchor.text,
    occurrence: anchor.occurrence,
  };

  if (anchor.placement !== undefined && anchor.placement !== DEFAULT_ANCHOR_PLACEMENT) {
    canonical.placement = anchor.placement;
  }

  if (anchor.origin !== undefined) {
    canonical.origin = anchor.origin;
  }

  if (anchor.offset !== undefined) {
    canonical.offset = {
      dx: roundCoordinate(anchor.offset.dx),
      dy: roundCoordinate(anchor.offset.dy),
    };
  }

  if (anchor.required !== undefined && anchor.required !== DEFAULT_ANCHOR_REQUIRED) {
    canonical.required = anchor.required;
  }

  if (anchor.tolerance !== undefined) {
    canonical.tolerance = roundCoordinate(anchor.tolerance);
  }

  if (anchor.resolved !== undefined) {
    canonical.resolved = {
      document_sha256: anchor.resolved.document_sha256,
      page: anchor.resolved.page,
      occurrence_index: anchor.resolved.occurrence_index,
      anchor_rect: canonicaliseRect(anchor.resolved.anchor_rect),
      rect: canonicaliseRect(anchor.resolved.rect),
    };
  }

  return canonical;
}

// --- checks ------------------------------------------------------------------------------

function checkSchemaVersion(document: Record<string, unknown>, issues: ValidationIssue[]): void {
  if (!("schema_version" in document)) {
    return;
  }

  const declared = document["schema_version"];

  if (typeof declared !== "string") {
    issues.push(
      issue("/schema_version", "invalid_type", `schema_version must be a string such as "${FIELD_SCHEMA_VERSION}".`),
    );

    return;
  }

  const match = /^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/.exec(declared);

  if (match === null) {
    issues.push(
      issue(
        "/schema_version",
        "schema_version_unsupported",
        `schema_version must be exactly MAJOR.MINOR, for example "${FIELD_SCHEMA_VERSION}"; got "${declared}".`,
      ),
    );

    return;
  }

  const [supportedMajor, supportedMinor] = FIELD_SCHEMA_VERSION.split(".").map(Number) as [number, number];
  const major = Number(match[1]);
  const minor = Number(match[2]);

  if (major !== supportedMajor) {
    issues.push(
      issue(
        "/schema_version",
        "schema_version_unsupported",
        `Unknown major schema version ${major}. This build implements ${supportedMajor}.x and refuses an unknown major version outright.`,
      ),
    );

    return;
  }

  if (minor > supportedMinor) {
    issues.push(
      issue(
        "/schema_version",
        "schema_version_unsupported",
        `schema_version ${declared} is newer than the ${FIELD_SCHEMA_VERSION} this build implements, so it may contain properties that would be dropped.`,
      ),
    );
  }
}

function checkCoordinateSpace(document: Record<string, unknown>, issues: ValidationIssue[]): void {
  if (!("coordinate_space" in document)) {
    return;
  }

  const space = document["coordinate_space"];

  if (!isObject(space)) {
    issues.push(
      issue(
        "/coordinate_space",
        "invalid_type",
        "coordinate_space must be an object declaring unit, origin, page_box, rotation, and page_index_base.",
      ),
    );

    return;
  }

  const expected = Object.entries(NATIVE_COORDINATE_SPACE);
  checkObjectShape(
    "/coordinate_space",
    space,
    expected.map(([key]) => key),
    [],
    issues,
  );

  for (const [key, value] of expected) {
    if (!(key in space)) {
      continue;
    }

    if (space[key] !== value) {
      issues.push(
        issue(
          `/coordinate_space/${key}`,
          "unsupported_coordinate_space",
          `Unsupported coordinate_space.${key}: schema ${FIELD_SCHEMA_VERSION} implements only ${JSON.stringify(value)}, got ${JSON.stringify(space[key])}. ` +
            "A document declaring another convention is rejected, never reinterpreted.",
        ),
      );
    }
  }
}

function checkRecipients(document: Record<string, unknown>, issues: ValidationIssue[]): string[] | null {
  if (!("recipients" in document)) {
    return null;
  }

  const recipients = document["recipients"];

  if (!Array.isArray(recipients)) {
    issues.push(issue("/recipients", "invalid_type", "recipients must be an array."));

    return null;
  }

  if (recipients.length === 0) {
    issues.push(issue("/recipients", "empty_collection", "recipients must declare at least one recipient."));

    return [];
  }

  const ids: string[] = [];

  recipients.forEach((recipient: unknown, index: number) => {
    const path = `/recipients/${index}`;

    if (!isObject(recipient)) {
      issues.push(issue(path, "invalid_type", "A recipient must be an object."));

      return;
    }

    checkObjectShape(path, recipient, RECIPIENT_REQUIRED, RECIPIENT_OPTIONAL, issues);

    if ("id" in recipient) {
      const id = checkIdentifier(`${path}/id`, "recipient id", recipient["id"], issues);

      if (id !== null) {
        if (ids.includes(id)) {
          issues.push(
            issue(
              `${path}/id`,
              "duplicate_id",
              `Duplicate recipient id "${id}". Recipient ids are the handles fields and the signing order refer to, so they must be unique.`,
            ),
          );
        } else {
          ids.push(id);
        }
      }
    }

    if ("name" in recipient) {
      checkNonEmptyString(`${path}/name`, "recipient name", recipient["name"], NAME_MAX_LENGTH, issues);
    }

    if ("email" in recipient) {
      checkEmail(`${path}/email`, recipient["email"], issues);
    }

    if ("role" in recipient) {
      checkNonEmptyString(`${path}/role`, "recipient role label", recipient["role"], ROLE_MAX_LENGTH, issues);
    }
  });

  return ids;
}

function checkSigningOrder(
  document: Record<string, unknown>,
  recipientIds: string[] | null,
  issues: ValidationIssue[],
): void {
  if (!("signing_order" in document)) {
    return;
  }

  const order = document["signing_order"];

  if (!Array.isArray(order)) {
    issues.push(
      issue(
        "/signing_order",
        "invalid_type",
        "signing_order must be an array of stages, each stage an array of recipient ids.",
      ),
    );

    return;
  }

  if (order.length === 0) {
    issues.push(issue("/signing_order", "empty_collection", "signing_order must declare at least one stage."));

    return;
  }

  const placed: string[] = [];
  let usable = true;

  order.forEach((stage: unknown, stageIndex: number) => {
    const stagePath = `/signing_order/${stageIndex}`;

    if (!Array.isArray(stage)) {
      issues.push(
        issue(
          stagePath,
          "invalid_type",
          'A signing stage must be an array of recipient ids; wrap a single recipient as ["id"].',
        ),
      );
      usable = false;

      return;
    }

    if (stage.length === 0) {
      issues.push(issue(stagePath, "empty_collection", "A signing stage must contain at least one recipient."));
      usable = false;

      return;
    }

    stage.forEach((id: unknown, position: number) => {
      const path = `${stagePath}/${position}`;

      if (typeof id !== "string") {
        issues.push(issue(path, "invalid_type", "A signing stage entry must be a recipient id string."));
        usable = false;

        return;
      }

      if (placed.includes(id)) {
        issues.push(
          issue(
            path,
            "recipient_duplicated_in_signing_order",
            `Recipient "${id}" appears more than once in signing_order. Each recipient signs in exactly one stage.`,
          ),
        );

        return;
      }

      placed.push(id);

      if (recipientIds !== null && !recipientIds.includes(id)) {
        issues.push(
          issue(
            path,
            "unknown_recipient",
            `signing_order names recipient "${id}", which is not declared in recipients.`,
          ),
        );
      }
    });
  });

  if (!usable || recipientIds === null) {
    return;
  }

  recipientIds.forEach((id: string, index: number) => {
    if (!placed.includes(id)) {
      issues.push(
        issue(
          `/recipients/${index}/id`,
          "recipient_not_in_signing_order",
          `Recipient "${id}" is declared but appears in no signing_order stage, so they would never be asked to sign.`,
        ),
      );
    }
  });
}

function checkFields(
  document: Record<string, unknown>,
  recipientIds: string[] | null,
  options: ValidationOptions,
  issues: ValidationIssue[],
): void {
  if (!("fields" in document)) {
    return;
  }

  const fields = document["fields"];

  if (!Array.isArray(fields)) {
    issues.push(issue("/fields", "invalid_type", "fields must be an array."));

    return;
  }

  const ids: string[] = [];
  const aliases: string[] = [];

  fields.forEach((field: unknown, index: number) => {
    const path = `/fields/${index}`;

    if (!isObject(field)) {
      issues.push(issue(path, "invalid_type", "A field must be an object."));

      return;
    }

    checkObjectShape(path, field, FIELD_REQUIRED, FIELD_OPTIONAL, issues);

    if ("id" in field) {
      const id = checkIdentifier(`${path}/id`, "field id", field["id"], issues);

      if (id !== null) {
        if (ids.includes(id)) {
          issues.push(
            issue(
              `${path}/id`,
              "duplicate_id",
              `Duplicate field id "${id}". Field ids are stable across import and export, so they must be unique.`,
            ),
          );
        } else {
          ids.push(id);
        }
      }
    }

    if ("alias" in field) {
      const alias = checkIdentifier(`${path}/alias`, "field alias", field["alias"], issues);

      if (alias !== null) {
        if (aliases.includes(alias)) {
          issues.push(
            issue(
              `${path}/alias`,
              "duplicate_alias",
              `Duplicate field alias "${alias}". Templates address fields by alias, so an alias resolves to exactly one field.`,
            ),
          );
        } else {
          aliases.push(alias);
        }
      }
    }

    if ("recipient_id" in field) {
      const recipientId = checkIdentifier(`${path}/recipient_id`, "recipient_id", field["recipient_id"], issues);

      if (recipientId !== null && recipientIds !== null && !recipientIds.includes(recipientId)) {
        issues.push(
          issue(
            `${path}/recipient_id`,
            "unknown_recipient",
            `Field is bound to recipient "${recipientId}", which is not declared in recipients.`,
          ),
        );
      }
    }

    if ("type" in field) {
      checkFieldType(`${path}/type`, field["type"], issues);
    }

    const page =
      "page" in field
        ? checkPage(`${path}/page`, field["page"], options.pageSizes, refusesImprecision(document), issues)
        : null;

    if ("rect" in field) {
      checkRect(`${path}/rect`, field["rect"], page, options.pageSizes, refusesImprecision(document), issues);
    }

    for (const flag of ["required", "read_only"] as const) {
      if (flag in field && typeof field[flag] !== "boolean") {
        issues.push(issue(`${path}/${flag}`, "invalid_type", `${flag} must be a boolean.`));
      }
    }

    if ("label" in field) {
      checkNonEmptyString(`${path}/label`, "label", field["label"], LABEL_MAX_LENGTH, issues);
    }

    if ("prefill" in field) {
      checkPrefill(`${path}/prefill`, field["prefill"], options.variables, issues);
    }

    if ("anchor" in field) {
      const fieldRequired = typeof field["required"] === "boolean" ? field["required"] : true;
      checkAnchor(`${path}/anchor`, field["anchor"], fieldRequired, field["rect"], declaredMinor(document), issues);
    }
  });
}

function checkFieldType(path: string, type: unknown, issues: ValidationIssue[]): void {
  if (typeof type !== "string") {
    issues.push(issue(path, "invalid_type", "type must be a string."));

    return;
  }

  if ((FIELD_TYPES as readonly string[]).includes(type)) {
    return;
  }

  issues.push(
    issue(
      path,
      "unsupported_field_type",
      `Unsupported field type "${type}". Schema ${FIELD_SCHEMA_VERSION} implements ${FIELD_TYPES.join(", ")}. ` +
        "An unsupported type is rejected, never ignored: a silently dropped field is a field nobody was asked to complete.",
    ),
  );
}

function checkIntegerRange(path: string, label: string, value: number, issues: ValidationIssue[], code: ValidationCode): boolean {
  if (Math.abs(value) <= MAX_SCHEMA_INTEGER) {
    return true;
  }

  issues.push(
    issue(
      path,
      code,
      `${label} is at most ${MAX_SCHEMA_INTEGER}, the largest integer this schema's two implementations agree ` +
        "on: PHP counts to 2^63 and JavaScript stops being exact at 2^53, so a larger value means one thing in " +
        "the editor and another in the API.",
    ),
  );

  return false;
}

/** Whether this document's version refuses an over-precise coordinate rather than rounding it. */
function refusesImprecision(document: Record<string, unknown>): boolean {
  const minor = declaredMinor(document);

  return minor !== null && minor >= PRECISION_REFUSED_SINCE_MINOR;
}

/** A coordinate must arrive already canonical. Returns false when it did not. */
function checkPrecision(path: string, label: string, value: number, issues: ValidationIssue[]): boolean {
  if (!Number.isFinite(value) || isCanonical(value)) {
    return true;
  }

  issues.push(
    issue(
      path,
      "coordinate_too_precise",
      `${label} has more than ${CANONICAL_DECIMALS} decimal places. Documents carry canonical numbers, and ` +
        "this one is refused rather than rounded: rounding is a transformation, and two implementations that " +
        "both transform can disagree about the result — which would be a disagreement about the document's " +
        "digest. Round it yourself and send the result.",
    ),
  );

  return false;
}

function checkPage(
  path: string,
  page: unknown,
  pageSizes: PageSize[] | undefined,
  refuseImprecise: boolean,
  issues: ValidationIssue[],
): number | null {
  if (typeof page !== "number" || !Number.isInteger(page)) {
    issues.push(issue(path, "invalid_type", "page must be an integer."));

    return null;
  }

  if (page < 1) {
    issues.push(
      issue(path, "page_out_of_range", `page is 1-based (coordinate_space.page_index_base is 1); got ${page}.`),
    );

    return null;
  }

  // Gated by version like the precision rule, and for the same reason: 1.0 accepted a larger
  // integer, and refusing one now would be a semantic tightening of a published version.
  if (refuseImprecise && !checkIntegerRange(path, "page", page, issues, "page_out_of_range")) {
    return null;
  }

  if (pageSizes !== undefined && page > pageSizes.length) {
    issues.push(issue(path, "page_out_of_range", `page ${page} is beyond the ${pageSizes.length}-page document.`));
  }

  return page;
}

function checkRect(
  path: string,
  rect: unknown,
  page: number | null,
  pageSizes: PageSize[] | undefined,
  refuseImprecise: boolean,
  issues: ValidationIssue[],
): void {
  if (!isObject(rect)) {
    issues.push(issue(path, "invalid_type", "rect must be an object with x, y, width, and height."));

    return;
  }

  checkObjectShape(path, rect, RECT_REQUIRED, [], issues);

  const values: Partial<Record<(typeof RECT_REQUIRED)[number], number>> = {};

  for (const name of RECT_REQUIRED) {
    if (!(name in rect)) {
      continue;
    }

    const raw = rect[name];

    if (typeof raw !== "number") {
      issues.push(issue(`${path}/${name}`, "invalid_type", `rect.${name} must be a number.`));

      continue;
    }

    if (!Number.isFinite(raw)) {
      issues.push(
        issue(`${path}/${name}`, "coordinate_not_finite", `rect.${name} must be a finite number; got ${raw}.`),
      );

      continue;
    }

    // From 1.1 a finer value is refused rather than rounded: rounding would make the validator's
    // answer depend on a transformation the two implementations do not agree about. 1.0 rounds,
    // because refusing would be a semantic tightening of a published version
    // ({@link PRECISION_REFUSED_SINCE_MINOR}). Rounding *before* the checks rather than after is
    // the one change 1.0 gets, and it takes nothing away: it refuses only values that rounded into
    // an invalid state, which were never documents that worked.
    let value = raw;

    if (refuseImprecise) {
      if (!checkPrecision(`${path}/${name}`, `rect.${name}`, value, issues)) {
        continue;
      }
    } else {
      value = roundCoordinate(value);
    }

    if ((name === "x" || name === "y") && value < 0) {
      issues.push(
        issue(
          `${path}/${name}`,
          "coordinate_negative",
          `rect.${name} must not be negative: the native origin is the top-left corner of the displayed page, so a negative value is off the page.`,
        ),
      );

      continue;
    }

    if ((name === "width" || name === "height") && value <= 0) {
      issues.push(
        issue(`${path}/${name}`, "dimension_not_positive", `rect.${name} must be greater than zero; got ${value}.`),
      );

      continue;
    }

    values[name] = value;
  }

  const x = values.x;
  const y = values.y;
  const width = values.width;
  const height = values.height;

  if (x === undefined || y === undefined || width === undefined || height === undefined) {
    return;
  }

  if (page === null || pageSizes === undefined) {
    return;
  }

  const size = pageSizes[page - 1];

  if (size === undefined) {
    return;
  }

  const right = roundCoordinate(x + width);
  const bottom = roundCoordinate(y + height);

  if (right > size.width + CANONICAL_TOLERANCE || bottom > size.height + CANONICAL_TOLERANCE) {
    issues.push(
      issue(
        path,
        "rect_out_of_page",
        `rect extends to (${right}, ${bottom}) on page ${page}, which is ${size.width} by ${size.height} pt.`,
      ),
    );
  }
}

function checkPrefill(
  path: string,
  prefill: unknown,
  variables: string[] | undefined,
  issues: ValidationIssue[],
): void {
  if (!isObject(prefill)) {
    issues.push(issue(path, "invalid_type", "prefill must be an object with a variable."));

    return;
  }

  checkObjectShape(path, prefill, ["variable"], [], issues);

  if (!("variable" in prefill)) {
    return;
  }

  const variable = prefill["variable"];

  if (typeof variable !== "string") {
    issues.push(issue(`${path}/variable`, "invalid_type", "prefill.variable must be a string."));

    return;
  }

  if (variable === "" || variable.length > VARIABLE_MAX_LENGTH || !VARIABLE_PATTERN.test(variable)) {
    issues.push(
      issue(
        `${path}/variable`,
        "invalid_format",
        `prefill.variable must be a dotted lower-snake name such as "recipient.name"; got "${variable}".`,
      ),
    );

    return;
  }

  if (variables !== undefined && !variables.includes(variable)) {
    issues.push(
      issue(
        `${path}/variable`,
        "unresolved_prefill_variable",
        `prefill.variable "${variable}" cannot be resolved by the sending context. An unresolved variable is an error before send, never a blank field.`,
      ),
    );
  }
}

/**
 * The minor version a document declares, or null when it does not declare a usable one.
 *
 * Only used to decide which members a document may contain; every other check is
 * version-independent, and a document with no readable version has already been reported.
 */
function declaredMinor(document: Record<string, unknown>): number | null {
  const declared = document["schema_version"];

  if (typeof declared !== "string") {
    return null;
  }

  const match = /^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/.exec(declared);

  return match ? Number(match[2]) : null;
}

function checkAnchor(
  path: string,
  anchor: unknown,
  fieldRequired: boolean,
  fieldRect: unknown,
  minor: number | null,
  issues: ValidationIssue[],
): void {
  if (!isObject(anchor)) {
    issues.push(issue(path, "invalid_type", "anchor must be an object with the text to locate."));

    return;
  }

  checkObjectShape(path, anchor, ANCHOR_REQUIRED, ANCHOR_OPTIONAL, issues);

  // The version string has to be a contract, not a label: a document declaring 1.0 is read
  // against a file that forbids undeclared properties, so a 1.1 member in it is refused with the
  // code that consumer would use.
  if (minor !== null && minor < ANCHOR_MEMBERS_MINOR) {
    for (const member of ANCHOR_MEMBERS_SINCE_1_1) {
      if (member in anchor) {
        issues.push(
          issue(
            `${path}/${member}`,
            "unknown_property",
            `anchor.${member} arrived in schema 1.1, and this document declares 1.${minor}. Declare 1.1 to ` +
              `use it: a document that says 1.${minor} is read against a contract that does not have it, and ` +
              "refusing an undeclared property is what that contract does.",
          ),
        );
      }
    }
  }

  if ("text" in anchor) {
    checkNonEmptyString(`${path}/text`, "anchor.text", anchor["text"], ANCHOR_TEXT_MAX_LENGTH, issues);
  }

  if ("occurrence" in anchor) {
    checkAnchorOccurrence(
      `${path}/occurrence`,
      anchor["occurrence"],
      minor !== null && minor >= PRECISION_REFUSED_SINCE_MINOR,
      issues,
    );
  }

  const placement = checkAnchorPlacement(`${path}/placement`, anchor, issues) ?? DEFAULT_ANCHOR_PLACEMENT;
  checkAnchorRequired(path, anchor, fieldRequired, placement, issues);
  checkAnchorTolerance(`${path}/tolerance`, anchor, placement, issues);

  if ("resolved" in anchor) {
    const tolerance = typeof anchor["tolerance"] === "number" ? anchor["tolerance"] : null;
    checkResolvedAnchor(`${path}/resolved`, anchor["resolved"], placement, tolerance, fieldRect, issues);
  }

  if ("origin" in anchor) {
    const origin = anchor["origin"];

    if (typeof origin !== "string") {
      issues.push(issue(`${path}/origin`, "invalid_type", "anchor.origin must be a string."));
    } else if (!(ANCHOR_ORIGINS as readonly string[]).includes(origin)) {
      issues.push(
        issue(
          `${path}/origin`,
          "invalid_format",
          `anchor.origin must be one of ${ANCHOR_ORIGINS.join(", ")}; got "${origin}". ` +
            "Nothing is inferred from the sign of the offset.",
        ),
      );
    }
  }

  if (!("offset" in anchor)) {
    return;
  }

  const offset = anchor["offset"];

  if (!isObject(offset)) {
    issues.push(issue(`${path}/offset`, "invalid_type", "anchor.offset must be an object with dx and dy."));

    return;
  }

  checkObjectShape(`${path}/offset`, offset, ["dx", "dy"], [], issues);

  for (const name of ["dx", "dy"] as const) {
    if (!(name in offset)) {
      continue;
    }

    const value = offset[name];

    if (typeof value !== "number") {
      issues.push(issue(`${path}/offset/${name}`, "invalid_type", `anchor.offset.${name} must be a number.`));

      continue;
    }

    if (!Number.isFinite(value)) {
      issues.push(
        issue(
          `${path}/offset/${name}`,
          "coordinate_not_finite",
          `anchor.offset.${name} must be a finite number; got ${value}.`,
        ),
      );

      continue;
    }

    // `offset` is a 1.0 member, so it follows the version's rule like `rect` does.
    if (minor !== null && minor >= PRECISION_REFUSED_SINCE_MINOR) {
      checkPrecision(`${path}/offset/${name}`, `anchor.offset.${name}`, value, issues);
    }
  }
}

/**
 * `"sole"` or a 1-based index, and nothing else. See {@link AnchorOccurrence}.
 */
function checkAnchorPlacement(
  path: string,
  anchor: Record<string, unknown>,
  issues: ValidationIssue[],
): AnchorPlacement | null {
  if (!("placement" in anchor)) {
    return null;
  }

  const placement = anchor["placement"];

  if (typeof placement !== "string") {
    issues.push(issue(path, "invalid_type", "anchor.placement must be a string."));

    return null;
  }

  if (!(ANCHOR_PLACEMENTS as readonly string[]).includes(placement)) {
    issues.push(
      issue(
        path,
        "invalid_format",
        `anchor.placement must be one of ${ANCHOR_PLACEMENTS.join(", ")}; got "${placement}". ` +
          '"replace" lets the anchor decide where the field goes and keeps only the rectangle\'s size; ' +
          '"cross_check" keeps the declared rectangle and requires the anchor to agree with it. Omitting ' +
          'the property is "replace", which is what an anchor has always meant here; "cross_check" is the ' +
          "narrower mode and has to be stated.",
      ),
    );

    return null;
  }

  return placement as AnchorPlacement;
}

function checkAnchorRequired(
  path: string,
  anchor: Record<string, unknown>,
  fieldRequired: boolean,
  placement: AnchorPlacement,
  issues: ValidationIssue[],
): void {
  if (!("required" in anchor)) {
    return;
  }

  const required = anchor["required"];

  if (typeof required !== "boolean") {
    issues.push(issue(`${path}/required`, "invalid_type", "anchor.required must be a boolean."));

    return;
  }

  if (required) {
    return;
  }

  if (fieldRequired) {
    issues.push(
      issue(
        `${path}/required`,
        "anchor_optional_on_required_field",
        'anchor.required is false on a field whose own "required" is true. An absent anchor omits the field, ' +
          "and a required field that is never placed can never be completed. Make the field optional, or " +
          "require the anchor.",
      ),
    );

    return;
  }

  if (placement === "cross_check") {
    issues.push(
      issue(
        `${path}/required`,
        "invalid_format",
        'anchor.required false means an absent anchor omits the field, which contradicts anchor.placement ' +
          '"cross_check": there the declared rectangle is authoritative and the anchor only checks it, so an ' +
          'absent anchor has nothing to omit. Use "replace", or require the anchor.',
      ),
    );
  }
}

function checkAnchorTolerance(
  path: string,
  anchor: Record<string, unknown>,
  placement: AnchorPlacement,
  issues: ValidationIssue[],
): void {
  if (!("tolerance" in anchor)) {
    return;
  }

  const tolerance = anchor["tolerance"];

  if (typeof tolerance !== "number") {
    issues.push(issue(path, "invalid_type", "anchor.tolerance must be a number of points."));

    return;
  }

  if (!Number.isFinite(tolerance)) {
    issues.push(issue(path, "coordinate_not_finite", `anchor.tolerance must be a finite number; got ${tolerance}.`));

    return;
  }

  if (!checkPrecision(path, "anchor.tolerance", tolerance, issues)) {
    return;
  }

  if (tolerance < 0) {
    issues.push(
      issue(path, "invalid_format", `anchor.tolerance is a distance in points and must not be negative; got ${tolerance}.`),
    );

    return;
  }

  // Bounded so the canonical form stays total, not because a larger number is unreasonable: past
  // roughly 1e20 this runtime and PHP spell the same value differently (100000000000000000000
  // against 1.0e+20), so one document would canonicalise to two different digests.
  if (tolerance > ANCHOR_TOLERANCE_MAX) {
    issues.push(
      issue(
        path,
        "invalid_format",
        `anchor.tolerance is a distance on one page and must be at most ${ANCHOR_TOLERANCE_MAX} pt, PDF's ` +
          `largest page side; got ${tolerance}. The bound keeps the canonical form total: past about 1e17 this ` +
          "schema's two implementations spell the same number differently, and a document with two spellings " +
          "has two digests.",
      ),
    );

    return;
  }

  if (placement === "replace") {
    issues.push(
      issue(
        path,
        "invalid_format",
        'anchor.tolerance only means something with anchor.placement "cross_check". In "replace" mode the ' +
          "anchor decides the position outright, so there is no declared rectangle to be within a tolerance of.",
      ),
    );
  }
}

function checkResolvedAnchor(
  path: string,
  resolved: unknown,
  placement: AnchorPlacement,
  anchorTolerance: number | null,
  fieldRect: unknown,
  issues: ValidationIssue[],
): void {
  if (!isObject(resolved)) {
    issues.push(issue(path, "invalid_type", "anchor.resolved must be an object recording what resolution found."));

    return;
  }

  checkObjectShape(path, resolved, RESOLVED_ANCHOR_REQUIRED, [], issues);

  if ("document_sha256" in resolved) {
    const digest = resolved["document_sha256"];

    if (typeof digest !== "string" || !DOCUMENT_SHA256_PATTERN.test(digest)) {
      issues.push(
        issue(
          `${path}/document_sha256`,
          "invalid_format",
          "anchor.resolved.document_sha256 must be 64 lowercase hexadecimal characters: the digest of the " +
            "exact bytes the text was located in.",
        ),
      );
    }
  }

  for (const name of ["page", "occurrence_index"] as const) {
    if (!(name in resolved)) {
      continue;
    }

    const value = resolved[name];

    if (typeof value !== "number" || !Number.isInteger(value)) {
      issues.push(issue(`${path}/${name}`, "invalid_type", `anchor.resolved.${name} must be an integer.`));

      continue;
    }

    if (value < 1) {
      issues.push(
        issue(
          `${path}/${name}`,
          name === "page" ? "page_out_of_range" : "invalid_format",
          `anchor.resolved.${name} is 1-based; got ${value}.`,
        ),
      );

      continue;
    }

    checkIntegerRange(
      `${path}/${name}`,
      `anchor.resolved.${name}`,
      value,
      issues,
      name === "page" ? "page_out_of_range" : "invalid_format",
    );
  }

  // `anchor_rect` records where the text was, not where anything goes: a heading's ascender
  // legitimately starts above the CropBox edge, so it is never checked as a placement.
  if ("anchor_rect" in resolved) {
    checkMeasuredRect(`${path}/anchor_rect`, resolved["anchor_rect"], issues);
  }

  if (!("rect" in resolved)) {
    return;
  }

  // `resolved` is a 1.1 member, so it can only appear where the rule applies.
  checkRect(`${path}/rect`, resolved["rect"], null, undefined, true, issues);

  // And bounded, which the field's own rect is not: `$defs/resolved_rect` arrived in 1.1 and can
  // carry the bound, while `rect` is 1.0's and tightening it would change what this build accepts
  // for documents already valid (#105).
  checkCoordinateMagnitude(`${path}/rect`, resolved["rect"], issues);

  const recordedRect = resolved["rect"];

  if (!isObject(fieldRect) || !isObject(recordedRect)) {
    return;
  }

  if (placement === "replace") {
    // In `replace` mode the receipt's rectangle is where the field went, so the two must agree.
    // Compared canonically, because canonical values are what will be stored: comparing what the
    // caller wrote would accept a document that stops importing the moment it is written down.
    for (const name of RECT_REQUIRED) {
      if (typeof fieldRect[name] !== "number" || typeof recordedRect[name] !== "number") {
        return;
      }

      const declared = roundCoordinate(fieldRect[name] as number);
      const actual = roundCoordinate(recordedRect[name] as number);

      // Exact: in `replace` mode the receipt's rectangle *is* the field's, so there is no
      // disagreement a tolerance could be measuring in any of the four numbers.
      if (declared !== actual) {
        issues.push(
          issue(
            `${path}/rect/${name}`,
            "invalid_format",
            `anchor.resolved.rect must be the field's own rect when anchor.placement is "replace": the receipt ` +
              `records where the field was placed, and this one says ${actual} where the field says ${declared}.`,
          ),
        );

        return;
      }
    }

    return;
  }

  // A cross-check receipt is the record that the check passed, so it has to survive the check —
  // and against the tolerance the anchor itself states, because a receipt whose standard has to
  // be looked up elsewhere proves nothing about what was applied.
  if (anchorTolerance === null) {
    issues.push(
      issue(
        path,
        "missing_property",
        'A "cross_check" anchor carrying anchor.resolved must also state anchor.tolerance: the receipt is the ' +
          "record that the check passed, and without the distance it passed by there is nothing to check it against.",
      ),
    );

    return;
  }

  // Rounded first, and the tolerance with them: import canonicalises every coordinate to three
  // decimals independently, so a declared 330.0004 and a resolved 331.00179 are 1.00139 apart and
  // inside a stated 1.0004 — and canonicalise to 330, 331.002 and 1, which is 1.002 apart and
  // outside. Accepting that would emit a document its own next import refuses.
  const slack = roundCoordinate(anchorTolerance);

  for (const name of ["x", "y"] as const) {
    if (typeof fieldRect[name] !== "number" || typeof recordedRect[name] !== "number") {
      return;
    }

    const declared = roundCoordinate(fieldRect[name] as number);
    const actual = roundCoordinate(recordedRect[name] as number);
    // The stated bound, enforced exactly. Both sides and the tolerance are already canonical, so
    // the page-edge epsilon has nothing left to absorb and would only widen what the document
    // says — a receipt 0.001 pt outside a stated `tolerance: 0` would pass and then be stored
    // claiming it had been checked to zero. The distance is rounded rather than compared raw
    // because subtracting two three-decimal values can land a few ulps above the bound they sit
    // exactly on.
    const distance = roundCoordinate(Math.abs(declared - actual));

    if (distance > slack) {
      issues.push(
        issue(
          `${path}/rect/${name}`,
          "anchor_cross_check_failed",
          `anchor.resolved records a ${name} of ${actual} against a declared ${name} of ${declared}, which is ` +
            `${distance} pt apart and outside the ${slack} pt tolerance the anchor states. A receipt ` +
            "that records a failed check is not a record that the check passed.",
        ),
      );

      return;
    }
  }
}

/**
 * A rectangle that records where something was, rather than where something goes: finite, with
 * non-negative extents, and never checked against the page.
 */
function checkMeasuredRect(path: string, rect: unknown, issues: ValidationIssue[]): void {
  if (!isObject(rect)) {
    issues.push(issue(path, "invalid_type", "rect must be an object with x, y, width, and height."));

    return;
  }

  checkObjectShape(path, rect, RECT_REQUIRED, [], issues);

  for (const name of RECT_REQUIRED) {
    if (!(name in rect)) {
      continue;
    }

    const value = rect[name];

    if (typeof value !== "number") {
      issues.push(issue(`${path}/${name}`, "invalid_type", `rect.${name} must be a number.`));

      continue;
    }

    if (!Number.isFinite(value)) {
      issues.push(issue(`${path}/${name}`, "coordinate_not_finite", `rect.${name} must be a finite number; got ${value}.`));

      continue;
    }

    // Refused rather than rounded, for the reason given in checkRect().
    if (!checkPrecision(`${path}/${name}`, `rect.${name}`, value, issues)) {
      continue;
    }

    if ((name === "width" || name === "height") && value < 0) {
      issues.push(
        issue(`${path}/${name}`, "dimension_not_positive", `rect.${name} must not be negative; got ${value}.`),
      );

      continue;
    }

  }

  checkCoordinateMagnitude(path, rect, issues);
}

/**
 * Every component of a rectangle within the magnitude both implementations agree on.
 *
 * Shared by the measured rectangle and the resolved one, because the reason is shared and has
 * nothing to do with either being a measurement or a placement: past roughly 1e17 this runtime and
 * PHP spell the same number differently, so a document holding one canonicalises to two digests.
 * Nothing on a page is a page-side away from it, so the bound refuses nothing real.
 */
function checkCoordinateMagnitude(path: string, rect: unknown, issues: ValidationIssue[]): void {
  if (!isObject(rect)) {
    return;
  }

  for (const name of RECT_REQUIRED) {
    const value = rect[name];

    if (typeof value !== "number" || !Number.isFinite(value)) {
      continue;
    }

    if (Math.abs(value) > MEASURED_RECT_MAX_MAGNITUDE) {
      issues.push(
        issue(
          `${path}/${name}`,
          "invalid_format",
          `rect.${name} must be within ${MEASURED_RECT_MAX_MAGNITUDE} pt of the origin, PDF's largest page ` +
            `side; got ${value}. The bound keeps the canonical form total: past about 1e17 this schema's two ` +
            "implementations spell the same number differently, and a document with two spellings has two digests.",
        ),
      );
    }
  }
}

function checkAnchorOccurrence(
  path: string,
  occurrence: unknown,
  refuseImprecise: boolean,
  issues: ValidationIssue[],
): void {
  if (typeof occurrence === "string") {
    if (occurrence === ANCHOR_OCCURRENCE_SOLE) {
      return;
    }

    issues.push(
      issue(
        path,
        "invalid_format",
        `anchor.occurrence must be "${ANCHOR_OCCURRENCE_SOLE}" or a 1-based index; got "${occurrence}". ` +
          '"all" places one box per match, which a single field cannot represent: use one field per box.',
      ),
    );

    return;
  }

  if (typeof occurrence !== "number" || !Number.isInteger(occurrence)) {
    issues.push(
      issue(path, "invalid_type", `anchor.occurrence must be "${ANCHOR_OCCURRENCE_SOLE}" or an integer index.`),
    );

    return;
  }

  if (occurrence < 1) {
    issues.push(issue(path, "invalid_format", `anchor.occurrence indexes are 1-based; got ${occurrence}.`));

    return;
  }

  if (refuseImprecise) {
    checkIntegerRange(path, "anchor.occurrence", occurrence, issues, "invalid_format");
  }
}

/**
 * Missing required properties and undeclared extra properties. Undeclared properties are
 * refused rather than dropped: silently discarding a property is how a document that says
 * "read only" becomes one that says nothing.
 */
function checkObjectShape(
  path: string,
  value: Record<string, unknown>,
  required: readonly string[],
  optional: readonly string[],
  issues: ValidationIssue[],
): void {
  for (const key of required) {
    if (!(key in value)) {
      issues.push(
        issue(
          path,
          "missing_property",
          `Missing required property "${key}". A partial document is rejected, not completed with defaults.`,
        ),
      );
    }
  }

  const known = [...required, ...optional];

  for (const key of Object.keys(value)) {
    if (!known.includes(key)) {
      issues.push(
        issue(
          path === "" ? `/${key}` : `${path}/${key}`,
          "unknown_property",
          `Unknown property "${key}". Schema ${FIELD_SCHEMA_VERSION} declares ${known.join(", ")}; ` +
            "an undeclared property is refused rather than silently ignored.",
        ),
      );
    }
  }
}

function checkIdentifier(path: string, label: string, value: unknown, issues: ValidationIssue[]): string | null {
  if (typeof value !== "string") {
    issues.push(issue(path, "invalid_type", `${label} must be a string.`));

    return null;
  }

  if (value === "" || value.length > IDENTIFIER_MAX_LENGTH || !IDENTIFIER_PATTERN.test(value)) {
    issues.push(
      issue(
        path,
        "invalid_format",
        `${label} must be 1 to ${IDENTIFIER_MAX_LENGTH} characters of letters, digits, dot, underscore, or hyphen, ` +
          `starting with a letter or digit; got "${value}".`,
      ),
    );

    return null;
  }

  return value;
}

function checkNonEmptyString(
  path: string,
  label: string,
  value: unknown,
  maxLength: number,
  issues: ValidationIssue[],
): void {
  if (typeof value !== "string") {
    issues.push(issue(path, "invalid_type", `${label} must be a string.`));

    return;
  }

  if (value === "") {
    issues.push(issue(path, "invalid_format", `${label} must not be empty. Omit the property instead.`));

    return;
  }

  if ([...value].length > maxLength) {
    issues.push(
      issue(path, "invalid_format", `${label} must be at most ${maxLength} characters; got ${[...value].length}.`),
    );
  }
}

function checkEmail(path: string, value: unknown, issues: ValidationIssue[]): void {
  if (typeof value !== "string") {
    issues.push(issue(path, "invalid_type", "recipient email must be a string."));

    return;
  }

  if (value === "" || value.length > EMAIL_MAX_LENGTH || !EMAIL_PATTERN.test(value)) {
    issues.push(issue(path, "invalid_email", `recipient email must be a deliverable-looking address; got "${value}".`));
  }
}

function isObject(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function issue(path: string, code: ValidationCode, message: string): ValidationIssue {
  return { path, code, message };
}
