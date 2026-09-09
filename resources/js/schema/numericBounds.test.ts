import { parseFieldSchema, serializeFieldSchema, validateFieldSchema } from "./fieldSchema";
import { contractMembers, documentWith, type Json, pointer } from "./numericSweep.support";

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

function refuses(path: string, value: number): boolean {
  return validateFieldSchema(documentWith(path, value)).some((problem) => problem.path === pointer(path));
}

function assertRoundTrips(path: string, version: "1.0" | "1.1"): void {
  const document = documentWith(path, 0.0004, version);
  const at = (candidate: Json) => validateFieldSchema(candidate).filter((problem) => problem.path === pointer(path));

  if (at(document).length > 0) {
    return;
  }

  const stored = JSON.parse(serializeFieldSchema(parseFieldSchema(document))) as Json;

  expect(at(stored)).toEqual([]);
}

describe("the numeric round-trip sweep", () => {
  it.each(Object.keys(EXPECTATIONS))("%s survives its own round trip", (path) => {
    assertRoundTrips(path, "1.1");
  });

  /**
   * The same property for a 1.0 document, where the rule is different on purpose.
   *
   * 1.1 refuses an over-precise coordinate; 1.0 rounds it, because refusing would be a semantic
   * tightening of a published version. Both are swept, and each case says which version it means
   * rather than encoding whichever behaviour happens to be current. Only the members 1.0 declares
   * are swept, read from `field-schema-1.0.json` rather than from a list somebody kept.
   */
  it.each([...contractMembers("1.0").keys()])("%s survives its own round trip under 1.0", (path) => {
    assertRoundTrips(path, "1.0");
  });
});

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
