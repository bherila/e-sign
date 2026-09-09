import refusalsJson from "../../../tests/Fixtures/schema/numeric-refusals.json";
import { validateFieldSchema } from "./fieldSchema";
import { contractMembers, documentWith, pointer, violations } from "./numericSweep.support";

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
