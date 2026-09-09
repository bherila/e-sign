import fixtureJson from "../../../tests/Fixtures/schema/nda-two-signers.json";
import legacySchemaJson from "../../schema/field-schema-1.0.json";
import schemaJson from "../../schema/field-schema-1.1.json";

/**
 * Shared machinery for the three derived numeric sweeps. Test-only; nothing ships this.
 *
 * The member list is walked out of `field-schema-1.1.json` rather than written down, because a
 * hand-kept list of what the contract says is a second source of the same truth and drifts —
 * `anchor.resolved.rect` was once filed as a legacy `rect` because it is one by type, when it
 * arrived in 1.1. The file already knows where every number lives.
 */
export type Json = Record<string, any>;

export type MemberBounds = {
  type: string;
  minimum: number | null;
  exclusiveMinimum: number | null;
  maximum: number | null;
};

export const ANCHORED_FIELD = 5;
export const PLAIN_FIELD = 0;

/** Every numeric member of the contract, as a document path, with the constraints it declares. */
export function contractMembers(version: "1.0" | "1.1" = "1.1"): Map<string, MemberBounds> {
  const file = (version === "1.0" ? legacySchemaJson : schemaJson) as Json;
  const defs = file["$defs"];
  const found = new Map<string, MemberBounds>();

  const walk = (node: Json, path: string, seen: string[]): void => {
    if (typeof node["$ref"] === "string") {
      const name = (node["$ref"] as string).slice("#/$defs/".length);

      if (!seen.includes(name)) {
        walk(defs[name] as Json, path, [...seen, name]);
      }

      return;
    }

    for (const combinator of ["oneOf", "anyOf", "allOf"]) {
      for (const branch of (node[combinator] ?? []) as Json[]) {
        walk(branch, path, seen);
      }
    }

    if (node["type"] === "number" || node["type"] === "integer") {
      found.set(path, {
        type: node["type"] as string,
        minimum: (node["minimum"] as number) ?? null,
        exclusiveMinimum: (node["exclusiveMinimum"] as number) ?? null,
        maximum: (node["maximum"] as number) ?? null,
      });

      return;
    }

    if (node["type"] === "array" && node["items"] !== undefined) {
      walk(node["items"] as Json, `${path}[]`, seen);

      return;
    }

    for (const [key, child] of Object.entries((node["properties"] ?? {}) as Json)) {
      walk(child as Json, path === "" ? key : `${path}.${key}`, seen);
    }
  };

  walk(file, "", []);

  return new Map([...found.entries()].sort(([a], [b]) => a.localeCompare(b)));
}

/**
 * A document carrying `value` at `path`, with whatever relaxation the member needs to be reachable.
 *
 * Without the relaxation a sweep reports on the wrong rule: `tolerance` means nothing outside
 * `cross_check`, and a `replace` receipt's rect must equal the field's own, so an unrelated
 * refusal would answer before the property under test could.
 */
export function documentWith(path: string, value: number | string, version: "1.0" | "1.1" = "1.1"): Json {
  const document = JSON.parse(JSON.stringify(fixtureJson)) as Json;
  document["schema_version"] = version;
  const index = path.startsWith("fields[].anchor") ? ANCHORED_FIELD : PLAIN_FIELD;
  const field = document["fields"][index] as Json;

  if (path.startsWith("fields[].anchor.resolved")) {
    field["anchor"]["resolved"] = {
      document_sha256: "d".repeat(64),
      page: 2,
      occurrence_index: 1,
      anchor_rect: { x: 330, y: 622.4, width: 165.6, height: 12 },
      rect: field["rect"],
    };
  }

  if (path.startsWith("fields[].anchor.tolerance") || path.startsWith("fields[].anchor.resolved.rect")) {
    field["anchor"]["placement"] = "cross_check";
    field["anchor"]["tolerance"] = 14400;
  }

  const keys = path.replace("fields[].", "").split(".");
  let target: Json = field;

  keys.forEach((key, depth) => {
    if (depth === keys.length - 1) {
      target[key] = value;

      return;
    }

    target = target[key] as Json;
  });

  return document;
}

export function pointer(path: string): string {
  const index = path.startsWith("fields[].anchor") ? ANCHORED_FIELD : PLAIN_FIELD;

  return `/fields/${index}/${path.replace("fields[].", "").split(".").join("/")}`;
}

/** The value that violates each constraint the contract states for a member. */
export function violations(bounds: MemberBounds): Record<string, number | string> {
  const step = bounds.type === "integer" ? 1 : 0.001;
  const cases: Record<string, number | string> = { type: "not-a-number" };

  // Precision is a rule JSON Schema cannot state here — `multipleOf: 0.001` is a floating-point
  // division and ajv rejects thousands of legal three-decimal values — so it is enforced by the
  // importers and swept explicitly. An integer member cannot be too precise.
  if (bounds.type === "number") {
    cases["precision"] = 0.00049;
  }

  if (bounds.minimum !== null) {
    cases["minimum"] = bounds.minimum - step;
  }

  if (bounds.exclusiveMinimum !== null) {
    cases["exclusiveMinimum"] = bounds.exclusiveMinimum;
  }

  if (bounds.maximum !== null) {
    cases["maximum"] = bounds.maximum + step;
  }

  return cases;
}
