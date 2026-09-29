import fixtureJson from "../../../tests/Fixtures/schema/nda-two-signers.json";
import schemaJson from "../../schema/field-schema-1.1.json";
import type { Json } from "./numericSweep.support";

/**
 * Shared machinery for the three contract sweeps over string and enum members (issue #106).
 * Test-only; nothing ships this.
 *
 * The twin of `tests/Support/StringContractSweep.php`: the same walk, the same document builder
 * and the same probes, so both projections are asked identical questions. Members come from
 * `field-schema-1.1.json`; pattern probes come from `PATTERNS`, keyed by the pattern text, and a
 * pattern the contract gains without an entry throws rather than being swept with nothing.
 *
 * Lengths are probed with astral characters wherever the pattern admits them: JSON Schema counts
 * `maxLength` in code points, where `String.prototype.length` counts UTF-16 units and PHP's
 * `strlen()` counts bytes, so an ASCII probe cannot tell which one an importer uses.
 */
export interface StringMember {
  type: string | null;
  minLength: number | null;
  maxLength: number | null;
  pattern: string | null;
  enum: unknown[] | null;
}

/** One code point, four UTF-8 bytes, two UTF-16 units. */
export const ASTRAL = "\u{1F600}";

const ANCHORED_FIELD = 5;
const PREFILLED_FIELD = 2;
const PLAIN_FIELD = 0;
const PROBED_RECIPIENT = "buyer";

type PatternKind = "identifier" | "variable" | "email" | "sha256";

const PATTERNS: Record<string, PatternKind> = {
  "^[A-Za-z0-9][A-Za-z0-9._-]*$": "identifier",
  "^[a-z][a-z0-9_]*(\\.[a-z][a-z0-9_]*)*$": "variable",
  "^[^@\\s]+@[^@\\s]+\\.[^@\\s]+$": "email",
  "^[0-9a-f]{64}$": "sha256",
};

/** Every string or enum member of the contract, as a document path, with its constraints. */
export function stringMembers(): Map<string, StringMember> {
  const file = schemaJson as Json;
  const defs = file["$defs"] as Json;
  const found = new Map<string, StringMember>();

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

    const type = node["type"];
    const values = "const" in node ? [node["const"]] : ((node["enum"] as unknown[] | undefined) ?? null);

    // Fail closed on every shape this walk does not understand, rather than dropping a member the
    // coverage list could then never notice was missing.
    if (Array.isArray(type)) {
      throw new Error(`${path} declares a union type, which the string sweep cannot probe.`);
    }

    if (type !== "string" && ["pattern", "minLength", "maxLength", "format"].some((key) => key in node)) {
      throw new Error(`${path} states a string constraint without type string.`);
    }

    if (typeof node["additionalProperties"] === "object" || "patternProperties" in node) {
      throw new Error(`${path} admits members by schema rather than by name; the sweep cannot address them.`);
    }

    if ((type === "string" || values !== null) && found.has(path)) {
      throw new Error(`${path} is declared by two branches; the sweep would probe only one.`);
    }

    if (type === "string" || values !== null) {
      found.set(path, {
        type: typeof type === "string" ? type : null,
        minLength: (node["minLength"] as number | undefined) ?? null,
        maxLength: (node["maxLength"] as number | undefined) ?? null,
        pattern: (node["pattern"] as string | undefined) ?? null,
        enum: values,
      });

      return;
    }

    if (type === "array" && node["items"] !== undefined) {
      walk(node["items"] as Json, `${path}[]`, seen);

      return;
    }

    for (const [key, child] of Object.entries((node["properties"] ?? {}) as Json)) {
      walk(child as Json, path === "" ? key : `${path}.${key}`, seen);
    }
  };

  walk(file, "", []);

  // Byte order, as PHP's ksort() gives, so both sides list the members identically.
  return new Map([...found.entries()].sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0)));
}

function patternKind(pattern: string | null): PatternKind | null {
  if (pattern === null) {
    return null;
  }

  const kind = PATTERNS[pattern];

  if (kind === undefined) {
    throw new Error(
      `The contract states a pattern the string sweep has no probes for: ${pattern}. Add it to PATTERNS here ` +
        "and in tests/Support/StringContractSweep.php.",
    );
  }

  return kind;
}

/** A value of exactly `length` code points that satisfies the member's pattern, if any. */
export function satisfying(member: StringMember, length: number): string {
  switch (patternKind(member.pattern)) {
    case null:
      return ASTRAL.repeat(length);
    case "identifier":
      return `a${"9._-Z".repeat(length).slice(0, length - 1)}`;
    case "variable":
      return length < 3 ? "a".repeat(length) : `a.b${"_".repeat(length - 3)}`;
    case "email":
      return length < 14
        ? `a@${"b".repeat(Math.max(1, length - 4))}.c`
        : `${ASTRAL.repeat(length - 13)}@example.test`;
    case "sha256":
      return "d".repeat(length);
  }
}

function shortestLength(member: StringMember): number {
  switch (patternKind(member.pattern)) {
    case "email":
      return Math.max(5, member.minLength ?? 0);
    case "sha256":
      return 64;
    default:
      return Math.max(1, member.minLength ?? 0);
  }
}

function patternViolations(pattern: string): Record<string, string> {
  switch (patternKind(pattern)) {
    case "identifier":
      return { "leading punctuation": "-a", "trailing newline": "a\n" };
    case "variable":
      return { "upper case": "Recipient.name", "trailing newline": "recipient.name\n" };
    case "email":
      return {
        "no domain dot": "buyer@example",
        "unicode whitespace": "bu\u00A0yer@example.test",
        "trailing newline": "buyer@example.test\n",
      };
    case "sha256":
      return { "upper case": "D".repeat(64), "trailing newline": `${"d".repeat(64)}\n` };
    default:
      return {};
  }
}

/** Values the contract says this member accepts, at the edges of its constraints. */
export function accepted(member: StringMember): Record<string, unknown> {
  if (member.enum !== null) {
    return Object.fromEntries(member.enum.map((value) => [`enum ${JSON.stringify(value)}`, value]));
  }

  const values: Record<string, unknown> = { shortest: satisfying(member, shortestLength(member)) };

  if (member.maxLength !== null) {
    values["maxLength"] = satisfying(member, member.maxLength);
  }

  return values;
}

/** One value per constraint this member declares, violating exactly that constraint. */
export function violations(member: StringMember): Record<string, unknown> {
  // A boolean is the wrong type for every member here, including `anchor.occurrence`, whose
  // other branch accepts an integer.
  const cases: Record<string, unknown> = { type: true };

  if (member.enum !== null) {
    const first = member.enum[0];

    if (typeof first === "string") {
      const swapped = first.toUpperCase() === first ? first.toLowerCase() : first.toUpperCase();

      if (swapped !== first) {
        cases["enum / case"] = swapped;
      }

      cases["enum / trailing newline"] = `${first}\n`;
    } else {
      cases["enum / other"] = typeof first === "number" && Number.isInteger(first) ? first + 1 : null;
    }

    // Values the two runtimes decode or spell differently, so a refusal that quotes what was
    // declared cannot just print it: `{}` is an array in PHP, and 1e20 is `1.0e+20` there.
    cases["type / object"] = {};
    cases["type / large number"] = 1e20;
    cases["type / fraction"] = 1.5;

    return cases;
  }

  if (member.minLength !== null && member.minLength > 0) {
    cases["minLength"] = "a".repeat(member.minLength - 1);
  }

  if (member.maxLength !== null) {
    cases["maxLength"] = satisfying(member, member.maxLength + 1);
  }

  if (member.pattern !== null) {
    for (const [name, value] of Object.entries(patternViolations(member.pattern))) {
      cases[`pattern / ${name}`] = value;
    }
  }

  return cases;
}

function segments(path: string): (string | number)[] {
  const index = path.startsWith("fields[].anchor")
    ? ANCHORED_FIELD
    : path.startsWith("fields[].prefill")
      ? PREFILLED_FIELD
      : PLAIN_FIELD;
  const out: (string | number)[] = [];

  for (const key of path.split(".")) {
    if (key === "signing_order[][]") {
      out.push("signing_order", 0, 0);
    } else if (key.endsWith("[]")) {
      out.push(key.slice(0, -2), key === "fields[]" ? index : 0);
    } else {
      out.push(key);
    }
  }

  return out;
}

/** RFC 6901 pointer of the probed member in `stringDocumentWith()`'s document. */
export function stringPointer(path: string): string {
  return `/${segments(path).join("/")}`;
}

/**
 * A document carrying `value` at `path`, relaxed so that nothing but the member under test can
 * answer at its pointer. A recipient id is renamed everywhere it is referenced.
 */
export function stringDocumentWith(path: string, value: unknown): Json {
  const document = JSON.parse(JSON.stringify(fixtureJson)) as Json;
  document["schema_version"] = "1.1";

  if (["recipients[].id", "fields[].recipient_id", "signing_order[][]"].includes(path)) {
    document["recipients"][0]["id"] = value;
    document["signing_order"] = (document["signing_order"] as unknown[][]).map((stage) =>
      stage.map((member) => (member === PROBED_RECIPIENT ? value : member)),
    );

    for (const field of document["fields"] as Json[]) {
      if (field["recipient_id"] === PROBED_RECIPIENT) {
        field["recipient_id"] = value;
      }
    }

    return document;
  }

  if (path === "schema_version") {
    document["schema_version"] = value;

    return document;
  }

  if (path.startsWith("fields[].anchor.resolved")) {
    const field = document["fields"][ANCHORED_FIELD] as Json;
    field["anchor"]["resolved"] = {
      document_sha256: "d".repeat(64),
      page: field["page"],
      occurrence_index: 1,
      anchor_rect: { x: 330, y: 622.4, width: 165.6, height: 12 },
      rect: field["rect"],
    };
  }

  const keys = segments(path);
  let target: Json = document;

  keys.forEach((key, depth) => {
    if (depth === keys.length - 1) {
      target[key] = value;

      return;
    }

    target = target[key] as Json;
  });

  return document;
}
