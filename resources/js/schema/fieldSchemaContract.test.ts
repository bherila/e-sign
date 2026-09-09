import Ajv2020, { type ValidateFunction } from "ajv/dist/2020";

import fixtureJson from "../../../tests/Fixtures/schema/nda-two-signers.json";
import legacySchemaJson from "../../schema/field-schema-1.0.json";
import schemaJson from "../../schema/field-schema-1.1.json";
import {
  ANCHOR_OPTIONAL,
  ANCHOR_ORIGINS,
  ANCHOR_REQUIRED,
  DEFAULT_ANCHOR_ORIGIN,
  DOCUMENT_REQUIRED,
  FIELD_OPTIONAL,
  FIELD_REQUIRED,
  FIELD_SCHEMA_VERSION,
  FIELD_TYPES,
  MAX_SCHEMA_INTEGER,
  NATIVE_COORDINATE_SPACE,
  parseFieldSchema,
  RECIPIENT_OPTIONAL,
  RECIPIENT_REQUIRED,
  RECT_REQUIRED,
  serializeFieldSchema,
  validateFieldSchema,
  VALIDATION_CODES,
} from "./fieldSchema";

/**
 * `resources/schema/field-schema-1.1.json` is the published contract; these types are one
 * implementation of it and `app/Domain/Preparation/Schema` is the other. This file enforces the
 * contract directly with ajv and pins the TypeScript mirror against it. The PHP suite
 * (`tests/Unit/Preparation/Schema/FieldSchemaContractTest.php`) pins the same file from the
 * server side, so a change made on one side only fails a suite.
 */

const schema = schemaJson as unknown as Record<string, any>;

/**
 * `email` is registered as an always-valid format: the schema also carries a `pattern` for the
 * address, which is the check that actually runs, and pulling in `ajv-formats` for one advisory
 * annotation is not worth another dependency.
 */
const ajv = new Ajv2020({ allErrors: true, strict: true, formats: { email: true } });
const validate: ValidateFunction = ajv.compile(schema);

function errorsFor(document: unknown): string[] {
  validate(document);

  return (validate.errors ?? []).map((error) => `${error.instancePath} ${error.message ?? ""}`.trim());
}

function brokenFixture(mutate: (raw: Record<string, any>) => void): unknown {
  const document = JSON.parse(JSON.stringify(fixtureJson)) as Record<string, any>;
  mutate(document);

  return document;
}

describe("the published JSON Schema", () => {
  it("is a valid draft 2020-12 schema", () => {
    expect(schema["$schema"]).toBe("https://json-schema.org/draft/2020-12/schema");
    expect(validate).toBeInstanceOf(Function);
  });

  it("accepts the shared fixture", () => {
    expect(errorsFor(fixtureJson)).toEqual([]);
  });

  it("accepts the canonical output of the TypeScript serializer", () => {
    const canonical = JSON.parse(serializeFieldSchema(parseFieldSchema(fixtureJson))) as unknown;

    expect(errorsFor(canonical)).toEqual([]);
  });

  /**
   * The reason 1.1 is a separate file rather than an edit to 1.0.
   *
   * A document written before the new anchor members existed says `"1.0"`, and a consumer holding
   * the 1.0 contract validates it with `additionalProperties: false`. That contract is unchanged
   * here, the document still satisfies it, and this build still reads the document — and hands
   * back exactly the bytes it was given, version included, so the field-schema digest every
   * attestation binds is untouched.
   */
  it("keeps a 1.0 document valid against the unchanged 1.0 contract, and round trips it", () => {
    const legacy = JSON.parse(JSON.stringify(fixtureJson)) as Record<string, any>;
    legacy.schema_version = "1.0";

    const validateLegacy = new Ajv2020({ strict: false, allErrors: true }).compile(legacySchemaJson);

    expect(validateLegacy(legacy)).toBe(true);
    expect((validateLegacy.errors ?? []).map((error) => error.message)).toEqual([]);

    const round = JSON.parse(serializeFieldSchema(parseFieldSchema(legacy))) as Record<string, any>;

    expect(round.schema_version).toBe("1.0");
    expect(round).toEqual(legacy);
    expect(validateLegacy(round)).toBe(true);
  });

  it.each([
    ["an undeclared document property", (raw: Record<string, any>) => (raw.fields_v2 = [])],
    ["an undeclared field property", (raw: Record<string, any>) => (raw.fields[0].font_size = 12)],
    ["a missing section", (raw: Record<string, any>) => delete raw.recipients],
    ["an unknown field type", (raw: Record<string, any>) => (raw.fields[0].type = "radio_group")],
    ["a wrong schema_version", (raw: Record<string, any>) => (raw.schema_version = "2.0")],
    ["a bottom-left origin", (raw: Record<string, any>) => (raw.coordinate_space.origin = "bottom-left")],
    ["a page below one", (raw: Record<string, any>) => (raw.fields[0].page = 0)],
    ["a zero width", (raw: Record<string, any>) => (raw.fields[0].rect.width = 0)],
    ["a negative x", (raw: Record<string, any>) => (raw.fields[0].rect.x = -1)],
    ["an empty signing stage", (raw: Record<string, any>) => (raw.signing_order = [[]])],
    ["no recipients", (raw: Record<string, any>) => (raw.recipients = [])],
    ["an email that is not an address", (raw: Record<string, any>) => (raw.recipients[0].email = "buyer")],
    ["an id with illegal characters", (raw: Record<string, any>) => (raw.fields[0].id = "buyer signature!")],
    ["a malformed prefill variable", (raw: Record<string, any>) => (raw.fields[2].prefill.variable = "Recipient Name")],
    ["a zero anchor occurrence", (raw: Record<string, any>) => (raw.fields[5].anchor.occurrence = 0)],
    ["an anchor with no occurrence", (raw: Record<string, any>) => delete raw.fields[5].anchor.occurrence],
    ["an anchor occurrence of all", (raw: Record<string, any>) => (raw.fields[5].anchor.occurrence = "all")],
    ["an undeclared anchor origin", (raw: Record<string, any>) => (raw.fields[5].anchor.origin = "centre")],
    [
      "an optional anchor on a required field",
      (raw: Record<string, any>) => {
        raw.fields[9].anchor.required = false;
        raw.fields[9].required = true;
      },
    ],
    [
      "an optional anchor on a field that does not state required at all",
      (raw: Record<string, any>) => {
        raw.fields[9].anchor.required = false;
        delete raw.fields[9].required;
      },
    ],
    [
      "a tolerance with no cross-check to be the tolerance of",
      (raw: Record<string, any>) => (raw.fields[5].anchor.tolerance = 2),
    ],
    [
      "an optional anchor that only cross-checks a rectangle it cannot omit",
      (raw: Record<string, any>) => {
        raw.fields[9].anchor.required = false;
        raw.fields[9].anchor.placement = "cross_check";
      },
    ],
    [
      "a cross-check receipt with no tolerance to have passed by",
      (raw: Record<string, any>) => {
        raw.fields[5].anchor.placement = "cross_check";
        raw.fields[5].anchor.resolved = {
          document_sha256: "d".repeat(64),
          page: 2,
          occurrence_index: 1,
          anchor_rect: { x: 330, y: 622.4, width: 165.6, height: 12 },
          rect: raw.fields[5].rect,
        };
      },
    ],
  ])("rejects %s", (_name, mutate) => {
    expect(errorsFor(brokenFixture(mutate)).length).toBeGreaterThan(0);
  });

  /**
   * The published file and the importers have to give a client the same answer.
   *
   * A relationship between two properties is the easiest kind of rule for a contract file and an
   * importer to disagree about, because the file can only express it with a conditional and it is
   * tempting not to write one. The cost of the disagreement falls on an integration: it validates
   * against the published schema, is told the document conforms, and gets a 422 from the service.
   * These are the relationships 1.1 encodes, each asserted in both projections at once.
   */
  it.each([
    [
      "anchor_optional_on_required_field",
      (raw: Record<string, any>) => {
        raw.fields[9].anchor.required = false;
        raw.fields[9].required = true;
      },
      "/fields/9/anchor/required",
    ],
    [
      "invalid_format",
      (raw: Record<string, any>) => (raw.fields[5].anchor.tolerance = 2),
      "/fields/5/anchor/tolerance",
    ],
    [
      "invalid_format",
      (raw: Record<string, any>) => {
        raw.fields[9].anchor.required = false;
        raw.fields[9].anchor.placement = "cross_check";
      },
      "/fields/9/anchor/required",
    ],
  ])("refuses %s in the contract file and in the importer alike", (code, mutate, path) => {
    const document = brokenFixture(mutate);

    expect(errorsFor(document).length).toBeGreaterThan(0);
    expect(
      validateFieldSchema(document).filter((issue) => issue.code === code && issue.path === path),
    ).toHaveLength(1);
  });
});

/**
 * The one rule the published file cannot state, pinned with the measurement that decided it.
 *
 * A 1.1 document carrying `rect.width: 0.0004` satisfies the contract and is refused by both
 * importers as `coordinate_too_precise`. That gap is deliberate, and the alternative was measured
 * rather than assumed: `multipleOf: 0.001` is the only way JSON Schema can express "at most three
 * decimals", and a default-configured ajv rejects **13.6%** of legal three-decimal values under it,
 * because the check is a floating-point division. Encoding the rule would therefore break a
 * conforming client locally — refusing documents the service accepts — which is worse than the gap
 * it closes, since the gap costs a 422 only for values that are wrong anyway.
 *
 * (`ajv`'s `multipleOfPrecision` option makes it exact, and a consumer who sets it gets agreement.
 * That is a property of one library's configuration, not of the published contract, so the file
 * cannot rely on it.)
 *
 * This case exists so the gap is visible in the suite rather than only in prose, and so it fails
 * if it ever closes — at which point the rule should go into the file and this test should go.
 */
describe("the precision rule the contract cannot express", () => {
  it("is the only place ajv and the importer disagree about a 1.1 document", () => {
    const tooPrecise = brokenFixture((raw: Record<string, any>) => {
      raw.fields[0].rect.width = 0.0004;
    });

    expect(errorsFor(tooPrecise)).toEqual([]);
    expect(validateFieldSchema(tooPrecise).map((problem) => problem.code)).toEqual(["coordinate_too_precise"]);
  });

  it("would cost more to encode than it costs to leave out", () => {
    const encoded = new Ajv2020({ strict: true }).compile({ type: "number", multipleOf: 0.001 });
    const legal = Array.from({ length: 40001 }, (_, index) => (index - 20000) / 1000);
    const wronglyRejected = legal.filter((value) => !encoded(value));

    // The measurement that decided it: encoding the rule refuses one legal value in seven.
    expect(wronglyRejected.length).toBeGreaterThan(5000);
    expect(encoded(0.0004)).toBe(false);
  });
});

describe("the TypeScript mirror", () => {
  it("declares the same document properties as the schema", () => {
    expect(schema.required).toEqual([...DOCUMENT_REQUIRED]);
    expect(Object.keys(schema.properties)).toEqual([...DOCUMENT_REQUIRED]);
    expect(schema.additionalProperties).toBe(false);
  });

  it("declares the same schema version", () => {
    expect(schema.properties.schema_version.const).toBe(FIELD_SCHEMA_VERSION);
  });

  it("declares the same coordinate space, value by value", () => {
    const space = schema["$defs"].coordinate_space;

    expect(space.additionalProperties).toBe(false);
    expect(space.required).toEqual(Object.keys(NATIVE_COORDINATE_SPACE));

    for (const [key, value] of Object.entries(NATIVE_COORDINATE_SPACE)) {
      expect(space.properties[key].const).toBe(value);
    }
  });

  it("declares the same field types", () => {
    expect(schema["$defs"].field_type.enum).toEqual([...FIELD_TYPES]);
  });

  it("declares the same field properties", () => {
    const field = schema["$defs"].field;

    expect(field.additionalProperties).toBe(false);
    expect(field.required).toEqual([...FIELD_REQUIRED]);
    expect(Object.keys(field.properties)).toEqual([...FIELD_REQUIRED, ...FIELD_OPTIONAL]);
    expect(field.properties.required.default).toBe(true);
    expect(field.properties.read_only.default).toBe(false);
  });

  it("declares the same recipient properties", () => {
    const recipient = schema["$defs"].recipient;

    expect(recipient.additionalProperties).toBe(false);
    expect(recipient.required).toEqual([...RECIPIENT_REQUIRED]);
    expect(Object.keys(recipient.properties)).toEqual([...RECIPIENT_REQUIRED, ...RECIPIENT_OPTIONAL]);
  });

  it("declares the same anchor shape, with no default occurrence", () => {
    const anchor = schema["$defs"].anchor;

    expect(anchor.additionalProperties).toBe(false);
    expect(anchor.required).toEqual([...ANCHOR_REQUIRED]);
    expect(Object.keys(anchor.properties)).toEqual([...ANCHOR_REQUIRED, ...ANCHOR_OPTIONAL]);
    expect(anchor.properties.occurrence.default).toBeUndefined();
    expect(anchor.properties.occurrence.oneOf).toEqual([
      { const: "sole" },
      { type: "integer", minimum: 1, maximum: MAX_SCHEMA_INTEGER },
    ]);
    expect(anchor.properties.origin.enum).toEqual([...ANCHOR_ORIGINS]);
    expect(anchor.properties.origin.default).toBe(DEFAULT_ANCHOR_ORIGIN);
  });

  it("declares the same rect constraints", () => {
    const rect = schema["$defs"].rect;

    expect(rect.additionalProperties).toBe(false);
    expect(rect.required).toEqual([...RECT_REQUIRED]);
    expect(rect.properties.x.minimum).toBe(0);
    expect(rect.properties.y.minimum).toBe(0);
    expect(rect.properties.width.exclusiveMinimum).toBe(0);
    expect(rect.properties.height.exclusiveMinimum).toBe(0);
  });

  it("keeps the validation code list unique and stable", () => {
    expect(new Set(VALIDATION_CODES).size).toBe(VALIDATION_CODES.length);
    expect(VALIDATION_CODES).toHaveLength(24);
  });
});
