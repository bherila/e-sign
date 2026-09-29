import { parseFieldSchema, serializeFieldSchema, validateFieldSchema } from "./fieldSchema";
import type { Json } from "./numericSweep.support";
import { accepted, stringDocumentWith, stringMembers, stringPointer, violations } from "./stringSweep.support";

/**
 * Every string and enum member the published contract admits, probed at the edge of each
 * constraint it declares (issue #106).
 *
 * The twin of `tests/Unit/Preparation/Schema/StringContractSweepTest.php`: bounds (the edge of
 * every constraint accepted, one step past it refused) and round trip (an accepted value is stored
 * as sent and still accepted). Refusal agreement is in `refusalAgreement.test.ts`.
 */

/** Asserted against the derived list, so a member cannot be added or lost silently. */
const EXPECTED_MEMBERS = [
  "coordinate_space.origin",
  "coordinate_space.page_box",
  "coordinate_space.page_index_base",
  "coordinate_space.rotation",
  "coordinate_space.unit",
  "document_id",
  "fields[].alias",
  "fields[].anchor.occurrence",
  "fields[].anchor.origin",
  "fields[].anchor.placement",
  "fields[].anchor.resolved.document_sha256",
  "fields[].anchor.text",
  "fields[].id",
  "fields[].label",
  "fields[].prefill.variable",
  "fields[].recipient_id",
  "fields[].type",
  "recipients[].email",
  "recipients[].id",
  "recipients[].name",
  "recipients[].role",
  "schema_version",
  "signing_order[][]",
];

/** Values the canonical form omits because the contract declares them the default. */
const DEFAULTS: Record<string, unknown[]> = {
  "fields[].anchor.origin": ["top_left"],
  "fields[].anchor.placement": ["replace"],
};

const ABSENT = Symbol("absent");

function problemsAt(path: string, document: Json) {
  return validateFieldSchema(document).filter((problem) => problem.path === stringPointer(path));
}

function at(document: Json, pointer: string): unknown {
  let node: unknown = document;

  for (const segment of pointer.split("/").slice(1)) {
    if (node === null || typeof node !== "object" || !(segment in (node as Json))) {
      return ABSENT;
    }

    node = (node as Json)[segment];
  }

  return node;
}

const acceptedCases: [string, string, unknown][] = [];
const violatingCases: [string, string, unknown][] = [];

for (const [path, member] of stringMembers()) {
  for (const [edge, value] of Object.entries(accepted(member))) {
    acceptedCases.push([path, edge, value]);
  }

  for (const [constraint, value] of Object.entries(violations(member))) {
    violatingCases.push([path, constraint, value]);
  }
}

describe("the string contract sweep", () => {
  it("covers every string and enum member the contract declares", () => {
    expect([...stringMembers().keys()]).toEqual(EXPECTED_MEMBERS);
  });

  it.each(acceptedCases)("%s accepts %s", (path, _edge, value) => {
    expect(problemsAt(path, stringDocumentWith(path, value))).toEqual([]);
  });

  it.each(violatingCases)("%s refuses %s", (path, _constraint, value) => {
    expect(problemsAt(path, stringDocumentWith(path, value))).not.toEqual([]);
  });

  it.each(acceptedCases)("%s survives its own round trip at %s", (path, _edge, value) => {
    const stored = JSON.parse(serializeFieldSchema(parseFieldSchema(stringDocumentWith(path, value)))) as Json;

    expect(problemsAt(path, stored)).toEqual([]);

    const readBack = at(stored, stringPointer(path));

    if (readBack === ABSENT) {
      expect(DEFAULTS[path] ?? []).toContain(value);

      return;
    }

    expect(readBack).toEqual(value);
  });
});
