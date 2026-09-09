import fixtureJson from "../../../tests/Fixtures/schema/nda-two-signers.json";
import schemaJson from "../../schema/field-schema-1.1.json";
import { validateFieldSchema } from "./fieldSchema";

/**
 * Every numeric member the published contract admits, probed at its boundary, in this projection.
 *
 * The twin of `tests/Unit/Preparation/Schema/NumericBoundsSweepTest.php`: same members, same
 * probes, same expectations. Two properties matter, and the pair is the point.
 *
 * **The member list is derived from `field-schema-1.1.json`, never written by hand.** A hand-kept
 * list drifted once — `anchor.resolved.rect` was filed as a legacy `rect` because it is one by
 * type, when it arrived in 1.1 — so the sweep asks the file where the numbers live instead of
 * asking a person to remember. A member added without a probe fails the coverage test below.
 *
 * **The probe is the boundary, not a big number.** The sweep used to test `1e20` only, which looks
 * stronger and is strictly weaker: PHP decodes it as a float and took a different path from the
 * one the bound guards, so PHP accepted `2^53` where this runtime refused it. A bound is
 * distinguished from its absence by exactly two values, the largest accepted and the smallest
 * refused.
 */
type Json = Record<string, any>;

const ANCHORED_FIELD = 5;
const PLAIN_FIELD = 0;

/** Every numeric member of the contract, as a document path, with its declared maximum. */
function contractMembers(): Map<string, { type: string; maximum: number | null }> {
  const defs = (schemaJson as Json)["$defs"];
  const found = new Map<string, { type: string; maximum: number | null }>();

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
      found.set(path, { type: node["type"] as string, maximum: (node["maximum"] as number) ?? null });

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

  walk(schemaJson as Json, "", []);

  return new Map([...found.entries()].sort(([a], [b]) => a.localeCompare(b)));
}

/** The members this sweep knows about; the values are documentation beside the derived truth. */
const EXPECTATIONS: Record<string, string> = {
  "fields[].anchor.occurrence": "bounded: an integer both languages agree on",
  "fields[].anchor.offset.dx": "unbounded, 1.0 legacy (#105)",
  "fields[].anchor.offset.dy": "unbounded, 1.0 legacy (#105)",
  "fields[].anchor.resolved.anchor_rect.height": "bounded by the largest page side",
  "fields[].anchor.resolved.anchor_rect.width": "bounded by the largest page side",
  "fields[].anchor.resolved.anchor_rect.x": "bounded by the largest page side",
  "fields[].anchor.resolved.anchor_rect.y": "bounded by the largest page side",
  "fields[].anchor.resolved.occurrence_index": "bounded: an integer both languages agree on",
  "fields[].anchor.resolved.page": "bounded: an integer both languages agree on",
  "fields[].anchor.resolved.rect.height": "bounded by the largest page side",
  "fields[].anchor.resolved.rect.width": "bounded by the largest page side",
  "fields[].anchor.resolved.rect.x": "bounded by the largest page side",
  "fields[].anchor.resolved.rect.y": "bounded by the largest page side",
  "fields[].anchor.tolerance": "bounded by the largest page side",
  "fields[].page": "bounded: an integer both languages agree on",
  "fields[].rect.height": "unbounded, 1.0 legacy (#105)",
  "fields[].rect.width": "unbounded, 1.0 legacy (#105)",
  "fields[].rect.x": "unbounded, 1.0 legacy (#105)",
  "fields[].rect.y": "unbounded, 1.0 legacy (#105)",
};

function documentWith(path: string, value: number): Json {
  const document = JSON.parse(JSON.stringify(fixtureJson)) as Json;
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

  // `tolerance` only means anything in cross_check, and a `replace` receipt's rect must be the
  // field's own — which would refuse every probe on `resolved.rect` before its own bound could.
  // The widest legal tolerance isolates the bound under test.
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

function refuses(path: string, value: number): boolean {
  const index = path.startsWith("fields[].anchor") ? ANCHORED_FIELD : PLAIN_FIELD;
  const pointer = `/fields/${index}/${path.replace("fields[].", "").split(".").join("/")}`;

  return validateFieldSchema(documentWith(path, value)).some((problem) => problem.path === pointer);
}

describe("the numeric bounds sweep", () => {
  it("covers every numeric member the contract declares", () => {
    expect(Object.keys(EXPECTATIONS)).toEqual([...contractMembers().keys()]);
  });

  it.each(Object.keys(EXPECTATIONS))("%s is bounded exactly where the contract says", (path) => {
    const member = contractMembers().get(path)!;

    if (member.maximum === null) {
      expect(refuses(path, 1e20)).toBe(false);

      return;
    }

    const step = member.type === "integer" ? 1 : 0.001;

    expect(refuses(path, member.maximum)).toBe(false);
    expect(refuses(path, member.maximum + step)).toBe(true);
    expect(refuses(path, 1e20)).toBe(true);
  });
});
