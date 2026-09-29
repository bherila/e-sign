import refusalsJson from "../../../tests/Fixtures/schema/numeric-refusals.json";
import stringRefusalsJson from "../../../tests/Fixtures/schema/string-refusals.json";
import { validateFieldSchema } from "./fieldSchema";
import { contractMembers, documentWith, type Json, pointer, violations } from "./numericSweep.support";
import {
  stringDocumentWith,
  stringMembers,
  stringPointer,
  violations as stringViolations,
} from "./stringSweep.support";

/**
 * The two implementations must refuse the same document for the same stated reason.
 *
 * The twin of `tests/Unit/Preparation/Schema/RefusalAgreementSweepTest.php`. Both compute their
 * own refusals and assert against one generated artifact, so neither can drift silently: a change
 * on either side fails that side, and the artifact is regenerated deliberately.
 *
 * Only verdicts have ever been compared between these projections. Codes are API surface and a
 * message is what a person reads when their document is refused, so agreeing a document is invalid
 * while disagreeing about why is one contract in name only. The divergence that prompted this was
 * found by review, not by a test, and repaired for one helper — the instance rather than the class.
 */
const expected = refusalsJson as Record<string, { code: string | null; message: string }>;

describe("the refusal agreement sweep", () => {
  it("refuses every stated constraint exactly as the other implementation does", () => {
    const computed: Record<string, { code: string | null; message: string }> = {};

    for (const [path, bounds] of contractMembers()) {
      for (const [constraint, value] of Object.entries(violations(bounds))) {
        const problems = validateFieldSchema(documentWith(path, value)).filter(
          (problem) => problem.path === pointer(path),
        );

        computed[`${path} / ${constraint}`] =
          problems.length === 0
            ? { code: null, message: "ACCEPTED — a stated constraint that refuses nothing" }
            : { code: problems[0]!.code, message: problems[0]!.message };
      }
    }

    expect(Object.fromEntries(Object.entries(computed).sort(([a], [b]) => a.localeCompare(b)))).toEqual(expected);
  });
});

/**
 * Code points probed inside an email address: every character ECMA-262's `\s` matches, and the
 * near misses a hand-written whitespace class tends to get wrong. Mirrors
 * `RefusalAgreementSweepTest::EMAIL_WHITESPACE_PROBES`.
 */
const EMAIL_WHITESPACE_PROBES = [
  0x09, 0x0a, 0x0b, 0x0c, 0x0d, 0x20, 0x85, 0xa0, 0x1680, 0x180e, 0x2000, 0x2005, 0x200a, 0x200b, 0x200d, 0x2028,
  0x2029, 0x202f, 0x205f, 0x3000, 0xfeff,
];

function firstProblem(document: Json, at: string): { code: string | null; message: string } {
  const problems = validateFieldSchema(document).filter((problem) => problem.path === at);

  return problems.length === 0
    ? { code: null, message: "ACCEPTED" }
    : { code: problems[0]!.code, message: problems[0]!.message };
}

describe("the string refusal agreement sweep (issue #106)", () => {
  it("refuses every string and enum constraint exactly as the other implementation does", () => {
    const computed: Record<string, { code: string | null; message: string }> = {};

    for (const [path, member] of stringMembers()) {
      for (const [constraint, value] of Object.entries(stringViolations(member))) {
        computed[`${path} / ${constraint}`] = firstProblem(stringDocumentWith(path, value), stringPointer(path));
      }
    }

    for (const codePoint of EMAIL_WHITESPACE_PROBES) {
      const label = codePoint.toString(16).toUpperCase().padStart(4, "0");
      computed[`recipients[].email / U+${label}`] = firstProblem(
        stringDocumentWith("recipients[].email", `bu${String.fromCodePoint(codePoint)}yer@example.test`),
        stringPointer("recipients[].email"),
      );
    }

    // Byte order, matching PHP's ksort() of the artifact's keys.
    const sorted = Object.fromEntries(Object.entries(computed).sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0)));

    expect(sorted).toEqual(stringRefusalsJson);
    expect(Object.keys(sorted)).toEqual(Object.keys(stringRefusalsJson));
  });
});
