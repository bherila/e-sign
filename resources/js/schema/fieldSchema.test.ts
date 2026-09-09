import fixtureJson from "../../../tests/Fixtures/schema/nda-two-signers.json";
import {
  ANCHOR_TOLERANCE_MAX,
  CANONICAL_DECIMALS,
  FIELD_SCHEMA_VERSION,
  FIELD_TYPES,
  type FieldSchemaDocument,
  FieldSchemaError,
  parseFieldSchema,
  roundCoordinate,
  serializeFieldSchema,
  validateFieldSchema,
  type ValidationCode,
} from "./fieldSchema";

const LETTER: { width: number; height: number }[] = [
  { width: 612, height: 792 },
  { width: 612, height: 792 },
];

const VARIABLES = ["recipient.name", "recipient.company", "recipient.title", "envelope.agreement_date"];

/** The shared synthetic fixture, the same bytes the PHP suite imports. */
function fixture(): FieldSchemaDocument {
  return JSON.parse(JSON.stringify(fixtureJson)) as FieldSchemaDocument;
}

/** The fixture as an opaque record, for tests that break its shape. */
function brokenFixture(mutate: (document: Record<string, any>) => void): unknown {
  const document = JSON.parse(JSON.stringify(fixtureJson)) as Record<string, any>;
  mutate(document);

  return document;
}

describe("parseFieldSchema", () => {
  it("imports the shared fixture", () => {
    const document = parseFieldSchema(fixture());

    expect(document.schema_version).toBe(FIELD_SCHEMA_VERSION);
    expect(document.document_id).toBe("doc_synthetic_nda");
    expect(document.recipients.map((recipient) => recipient.id)).toEqual(["buyer", "counterparty"]);
    expect(document.signing_order).toEqual([["buyer"], ["counterparty"]]);
    expect(document.fields).toHaveLength(10);
  });

  it("accepts JSON text as well as a decoded document", () => {
    expect(parseFieldSchema(JSON.stringify(fixtureJson))).toEqual(parseFieldSchema(fixture()));
  });

  it("validates against page geometry and the sending context's variables", () => {
    expect(() => parseFieldSchema(fixture(), { pageSizes: LETTER, variables: VARIABLES })).not.toThrow();
  });

  it("throws with every issue attached", () => {
    expect.assertions(4);

    try {
      parseFieldSchema({ schema_version: "1.0" });
    } catch (error) {
      const failure = error as FieldSchemaError;
      expect(failure).toBeInstanceOf(FieldSchemaError);
      expect(failure.issues).toHaveLength(5);
      expect(failure.issues.every((issue) => issue.code === "missing_property")).toBe(true);
      expect(failure.message).toContain("5 issues");
    }
  });

  it("rejects JSON text that is not JSON", () => {
    expect(() => parseFieldSchema('{"schema_version":')).toThrow(FieldSchemaError);
  });

  it("rejects a JSON array", () => {
    expect(validateFieldSchema([])).toEqual([
      { path: "", code: "invalid_type", message: "Document must be a JSON object." },
    ]);
  });

  it("applies the fail-closed defaults for omitted flags", () => {
    const document = parseFieldSchema(
      brokenFixture((raw) => {
        delete raw.fields[0].required;
        delete raw.fields[0].read_only;
      }),
    );

    expect(document.fields[0]!.required).toBe(true);
    expect(document.fields[0]!.read_only).toBe(false);
  });

  it("keeps the anchor placement request verbatim", () => {
    const document = parseFieldSchema(fixture());

    // `placement` and `required` are absent because they equal their defaults, and the
    // canonical form omits a defaulted anchor property — which is what keeps a document written
    // before those properties existed byte-identical, and its digest with it.
    expect(document.fields[5]!.anchor).toEqual({
      text: "Counterparty signature:",
      occurrence: "sole",
      origin: "bottom_left",
      offset: { dx: 0, dy: 12.5 },
    });
    // The optional notes field carries the narrow compatibility option: its anchor may be
    // absent, and then the field is omitted rather than placed anywhere.
    expect(document.fields[9]!.anchor).toEqual({
      text: "Notes:",
      occurrence: 2,
      required: false,
    });
  });
});

describe("serializeFieldSchema", () => {
  it("round trips the fixture without drift", () => {
    // The fixture is pretty-printed for review; canonical form is compact with the same
    // property order, so re-encoding the fixture object is the expected canonical output. The
    // PHP suite asserts the identical property against the same bytes, which is what makes the
    // two canonical forms byte-identical.
    expect(serializeFieldSchema(parseFieldSchema(fixture()))).toBe(JSON.stringify(fixtureJson));
  });

  it("is idempotent", () => {
    const once = serializeFieldSchema(parseFieldSchema(fixture()));
    const twice = serializeFieldSchema(parseFieldSchema(once));

    expect(twice).toBe(once);
  });

  it("round trips a resolution receipt without drift", () => {
    // The editor reads back documents the service has already resolved: a published template
    // version, or an envelope. A receipt it could not re-emit byte for byte would show up as a
    // spurious unsaved change the moment somebody opened one.
    const json = serializeFieldSchema(
      parseFieldSchema(
        brokenFixture((raw) => {
          raw.fields[5].anchor.resolved = {
            document_sha256: "b".repeat(64),
            page: 2,
            occurrence_index: 1,
            anchor_rect: { x: 330, y: 622.4, width: 165.6, height: 12 },
            // In `replace` mode the receipt records where the field went, so it has to be the
            // field's own rectangle — within the canonical tolerance, which is what makes the
            // fractional tail here legal and the round trip exact.
            rect: { x: 330, y: 650.0004, width: 170, height: 36 },
          };
        }),
      ),
    );

    expect(json).toContain('"occurrence_index":1');
    expect(json).toContain('"rect":{"x":330,"y":650,"width":170,"height":36}');
    expect(serializeFieldSchema(parseFieldSchema(json))).toBe(json);
  });

  /**
   * The largest tolerance the schema allows still canonicalises to bytes PHP would write.
   *
   * `tolerance` is the first number in this schema with no page behind it, which is what made the
   * exponential range reachable at all — and in that range the canonical form is undefined,
   * because PHP writes `1.0e+20` where this runtime writes `100000000000000000000`. The schema
   * bounds the property rather than trying to agree on a spelling, so the top of the range is a
   * plain integer here and a plain integer there.
   */
  it("canonicalises the largest allowed tolerance as a plain integer", () => {
    const raw = JSON.parse(JSON.stringify(fixtureJson)) as Record<string, any>;
    raw.fields[5].anchor.placement = "cross_check";
    raw.fields[5].anchor.tolerance = ANCHOR_TOLERANCE_MAX;

    const json = serializeFieldSchema(parseFieldSchema(raw));

    expect(json).toContain(`"tolerance":${ANCHOR_TOLERANCE_MAX}`);
    expect(json).not.toContain("e+");
    expect(validateFieldSchema(JSON.parse(json))).toEqual([]);
  });

  it("omits a defaulted anchor property so an older document keeps its bytes", () => {
    const json = serializeFieldSchema(
      parseFieldSchema(
        brokenFixture((raw) => {
          raw.fields[5].anchor.placement = "replace";
          raw.fields[5].anchor.required = true;
        }),
      ),
    );

    // Written out explicitly, canonicalised away: an anchor authored before `placement` and
    // `required` existed must produce exactly the bytes it always produced, because the
    // field-schema digest is what every attestation on an anchored agreement is bound to.
    expect(json).toContain('"anchor":{"text":"Counterparty signature:","occurrence":"sole","origin":"bottom_left"');
    expect(json).not.toContain('"placement":"replace"');
    expect(json).toBe(serializeFieldSchema(parseFieldSchema(fixture())));
  });

  it("keeps a non-default placement and a false anchor.required", () => {
    const json = serializeFieldSchema(
      parseFieldSchema(
        brokenFixture((raw) => {
          raw.fields[5].anchor.placement = "cross_check";
          raw.fields[5].anchor.tolerance = 2;
        }),
      ),
    );

    expect(json).toContain('"placement":"cross_check"');
    expect(json).toContain('"tolerance":2');
    expect(json).toContain('"text":"Notes:","occurrence":2,"required":false');
  });

  it("preserves stable field ids and template aliases", () => {
    const reimported = parseFieldSchema(serializeFieldSchema(parseFieldSchema(fixture())));
    const aliased = reimported.fields.find((field) => field.alias === "counterparty_signature_block");

    expect(aliased?.id).toBe("counterparty_signature");
    expect(reimported.fields.map((field) => field.id)).toEqual(
      fixture().fields.map((field) => field.id),
    );
  });

  it("writes integral coordinates without a fractional part and rounds to three decimals", () => {
    const json = serializeFieldSchema(
      parseFieldSchema(
        brokenFixture((raw) => {
          raw.fields[0].rect = { x: 60.00049, y: 650.0, width: 170.4567, height: 36 };
        }),
      ),
    );

    expect(json).toContain('"x":60,"y":650,"width":170.457,"height":36');
    expect(serializeFieldSchema(parseFieldSchema(json))).toBe(json);
  });

  it("survives a thousand round trips of generated documents without coordinate drift", () => {
    let seed = 20250908;
    const random = (): number => {
      // Deterministic 32-bit LCG: a failure here has to be reproducible.
      seed = (Math.imul(seed, 1664525) + 1013904223) >>> 0;

      return seed / 4294967296;
    };
    const between = (min: number, max: number, decimals: number): number =>
      roundCoordinate(Number((min + (max - min) * random()).toFixed(decimals)));

    for (let index = 0; index < 1000; index++) {
      const recipientCount = 1 + Math.floor(random() * 3);
      const ids = Array.from({ length: recipientCount }, (_unused, i) => `r${index}-${i}`);
      const fieldCount = Math.floor(random() * 8);

      const document: FieldSchemaDocument = {
        schema_version: FIELD_SCHEMA_VERSION,
        document_id: `doc${index}`,
        coordinate_space: {
          unit: "pt",
          origin: "top-left",
          page_box: "crop",
          rotation: "displayed",
          page_index_base: 1,
        },
        recipients: ids.map((id, i) => ({ id, name: `Example Party ${i}`, email: `party${i}@example.test` })),
        signing_order: [ids],
        fields: Array.from({ length: fieldCount }, (_unused, i) => {
          const width = between(1, 200, Math.floor(random() * 4));
          const height = between(1, 60, Math.floor(random() * 4));

          return {
            id: `f${index}-${i}`,
            recipient_id: ids[Math.floor(random() * recipientCount)]!,
            type: FIELD_TYPES[Math.floor(random() * FIELD_TYPES.length)]!,
            page: 1 + Math.floor(random() * 6),
            rect: {
              x: between(0, Math.floor(612 - width), Math.floor(random() * 4)),
              y: between(0, Math.floor(792 - height), Math.floor(random() * 4)),
              width,
              height,
            },
            required: random() < 0.5,
            read_only: random() < 0.5,
          };
        }),
      };

      const json = serializeFieldSchema(document);
      const reimported = parseFieldSchema(json);

      expect(serializeFieldSchema(reimported)).toBe(json);
      expect(reimported).toEqual(document);
    }
  });
});

describe("roundCoordinate", () => {
  it("keeps three decimals, rounding half away from zero like PHP's round()", () => {
    expect(CANONICAL_DECIMALS).toBe(3);
    expect(roundCoordinate(60.1234)).toBe(60.123);
    expect(roundCoordinate(60.1235)).toBe(60.124);
    expect(roundCoordinate(60.0004)).toBe(60);
    expect(roundCoordinate(60.0005)).toBe(60.001);
    expect(roundCoordinate(-1.2345)).toBe(-1.235);
    expect(roundCoordinate(612)).toBe(612);
  });

  it("is idempotent", () => {
    for (const value of [0.0005, 1.4445, 99.9995, 612, 0.001, 1e-7]) {
      expect(roundCoordinate(roundCoordinate(value))).toBe(roundCoordinate(value));
    }
  });

  it("leaves non-finite values alone for the validator to report", () => {
    expect(roundCoordinate(Number.NaN)).toBeNaN();
    expect(roundCoordinate(Number.POSITIVE_INFINITY)).toBe(Number.POSITIVE_INFINITY);
  });
});

describe("validateFieldSchema", () => {
  it("accepts the shared fixture", () => {
    expect(validateFieldSchema(fixture())).toEqual([]);
  });

  it("accepts an empty field list as a valid draft", () => {
    expect(validateFieldSchema(brokenFixture((raw) => (raw.fields = [])))).toEqual([]);
  });

  /**
   * The mirror of `FieldSchemaValidatorTest::rejectionCases()` in PHP: same break, same code,
   * same JSON Pointer. Both suites work from the same fixture, so a divergence between the
   * editor's rejection rules and the server's fails here.
   */
  const cases: [name: string, mutate: (raw: Record<string, any>) => void, code: ValidationCode, path: string][] = [
    ["a partial document missing whole sections", (raw) => delete raw.fields, "missing_property", ""],
    ["a field missing its rect", (raw) => delete raw.fields[0].rect, "missing_property", "/fields/0"],
    ["an undeclared document property", (raw) => (raw.fields_v2 = []), "unknown_property", "/fields_v2"],
    ["an undeclared field property", (raw) => (raw.fields[1].font_size = 12), "unknown_property", "/fields/1/font_size"],
    [
      "an undeclared rect property",
      (raw) => (raw.fields[0].rect.rotation = 90),
      "unknown_property",
      "/fields/0/rect/rotation",
    ],
    ["a missing schema_version", (raw) => delete raw.schema_version, "missing_property", ""],
    ["an unknown major schema_version", (raw) => (raw.schema_version = "2.0"), "schema_version_unsupported", "/schema_version"],
    ["a newer minor schema_version", (raw) => (raw.schema_version = "1.7"), "schema_version_unsupported", "/schema_version"],
    ["a malformed schema_version", (raw) => (raw.schema_version = "v1"), "schema_version_unsupported", "/schema_version"],
    ["a non-string schema_version", (raw) => (raw.schema_version = 1), "invalid_type", "/schema_version"],
    [
      "a coordinate space in the wrong unit",
      (raw) => (raw.coordinate_space.unit = "percent"),
      "unsupported_coordinate_space",
      "/coordinate_space/unit",
    ],
    [
      "a bottom-left origin",
      (raw) => (raw.coordinate_space.origin = "bottom-left"),
      "unsupported_coordinate_space",
      "/coordinate_space/origin",
    ],
    [
      "the media box instead of the crop box",
      (raw) => (raw.coordinate_space.page_box = "media"),
      "unsupported_coordinate_space",
      "/coordinate_space/page_box",
    ],
    [
      "unrotated coordinates",
      (raw) => (raw.coordinate_space.rotation = "raw"),
      "unsupported_coordinate_space",
      "/coordinate_space/rotation",
    ],
    [
      "zero-based page numbering",
      (raw) => (raw.coordinate_space.page_index_base = 0),
      "unsupported_coordinate_space",
      "/coordinate_space/page_index_base",
    ],
    [
      "an incomplete coordinate space",
      (raw) => delete raw.coordinate_space.rotation,
      "missing_property",
      "/coordinate_space",
    ],
    ["a coordinate space that is not an object", (raw) => (raw.coordinate_space = "native"), "invalid_type", "/coordinate_space"],
    [
      "a duplicate recipient id",
      (raw) => {
        raw.recipients[1].id = "buyer";
        raw.signing_order = [["buyer"]];
      },
      "duplicate_id",
      "/recipients/1/id",
    ],
    ["a duplicate field id", (raw) => (raw.fields[1].id = "buyer_signature"), "duplicate_id", "/fields/1/id"],
    [
      "a duplicate template alias",
      (raw) => (raw.fields[5].alias = "buyer_signature_block"),
      "duplicate_alias",
      "/fields/5/alias",
    ],
    ["an id with illegal characters", (raw) => (raw.fields[0].id = "buyer signature!"), "invalid_format", "/fields/0/id"],
    ["an empty document_id", (raw) => (raw.document_id = ""), "invalid_format", "/document_id"],
    [
      "a field bound to a nonexistent recipient",
      (raw) => (raw.fields[0].recipient_id = "witness"),
      "unknown_recipient",
      "/fields/0/recipient_id",
    ],
    [
      "a signing order naming a nonexistent recipient",
      (raw) => raw.signing_order[1].push("witness"),
      "unknown_recipient",
      "/signing_order/1/1",
    ],
    [
      "a recipient in no signing stage",
      (raw) => (raw.signing_order = [["buyer"]]),
      "recipient_not_in_signing_order",
      "/recipients/1/id",
    ],
    [
      "a recipient in two signing stages",
      (raw) => (raw.signing_order = [["buyer"], ["counterparty"], ["buyer"]]),
      "recipient_duplicated_in_signing_order",
      "/signing_order/2/0",
    ],
    [
      "a recipient twice in one signing stage",
      (raw) => (raw.signing_order = [["buyer", "buyer"], ["counterparty"]]),
      "recipient_duplicated_in_signing_order",
      "/signing_order/0/1",
    ],
    ["no recipients at all", (raw) => (raw.recipients = []), "empty_collection", "/recipients"],
    ["an empty signing order", (raw) => (raw.signing_order = []), "empty_collection", "/signing_order"],
    [
      "an empty signing stage",
      (raw) => (raw.signing_order = [["buyer"], [], ["counterparty"]]),
      "empty_collection",
      "/signing_order/1",
    ],
    [
      "a signing stage that is not an array",
      (raw) => (raw.signing_order = ["buyer", ["counterparty"]]),
      "invalid_type",
      "/signing_order/0",
    ],
    ["an unsupported field type", (raw) => (raw.fields[2].type = "radio_group"), "unsupported_field_type", "/fields/2/type"],
    ["a page below one", (raw) => (raw.fields[0].page = 0), "page_out_of_range", "/fields/0/page"],
    ["a negative page", (raw) => (raw.fields[0].page = -3), "page_out_of_range", "/fields/0/page"],
    ["a non-integer page", (raw) => (raw.fields[0].page = "1"), "invalid_type", "/fields/0/page"],
    ["a fractional page", (raw) => (raw.fields[0].page = 1.5), "invalid_type", "/fields/0/page"],
    ["a not-a-number coordinate", (raw) => (raw.fields[0].rect.x = Number.NaN), "coordinate_not_finite", "/fields/0/rect/x"],
    [
      "an infinite coordinate",
      (raw) => (raw.fields[0].rect.y = Number.POSITIVE_INFINITY),
      "coordinate_not_finite",
      "/fields/0/rect/y",
    ],
    ["a coordinate that is a numeric string", (raw) => (raw.fields[0].rect.x = "60"), "invalid_type", "/fields/0/rect/x"],
    ["a negative x", (raw) => (raw.fields[0].rect.x = -0.5), "coordinate_negative", "/fields/0/rect/x"],
    ["a negative y", (raw) => (raw.fields[0].rect.y = -1), "coordinate_negative", "/fields/0/rect/y"],
    ["a zero width", (raw) => (raw.fields[0].rect.width = 0), "dimension_not_positive", "/fields/0/rect/width"],
    ["a negative height", (raw) => (raw.fields[0].rect.height = -36), "dimension_not_positive", "/fields/0/rect/height"],
    ["a rect that is not an object", (raw) => (raw.fields[0].rect = [60, 650, 170, 36]), "invalid_type", "/fields/0/rect"],
    ["a non-boolean required flag", (raw) => (raw.fields[0].required = "yes"), "invalid_type", "/fields/0/required"],
    ["a non-boolean read_only flag", (raw) => (raw.fields[0].read_only = 0), "invalid_type", "/fields/0/read_only"],
    ["an empty label", (raw) => (raw.fields[0].label = ""), "invalid_format", "/fields/0/label"],
    ["an overlong label", (raw) => (raw.fields[0].label = "x".repeat(201)), "invalid_format", "/fields/0/label"],
    [
      "a malformed prefill variable",
      (raw) => (raw.fields[2].prefill.variable = "Recipient Name"),
      "invalid_format",
      "/fields/2/prefill/variable",
    ],
    ["a prefill without a variable", (raw) => (raw.fields[2].prefill = {}), "missing_property", "/fields/2/prefill"],
    [
      "an undeclared prefill property",
      (raw) => (raw.fields[2].prefill.fallback = "Anonymous"),
      "unknown_property",
      "/fields/2/prefill/fallback",
    ],
    ["an empty anchor text", (raw) => (raw.fields[5].anchor.text = ""), "invalid_format", "/fields/5/anchor/text"],
    ["an anchor without text", (raw) => delete raw.fields[5].anchor.text, "missing_property", "/fields/5/anchor"],
    [
      "an anchor that does not say which match it means",
      (raw) => delete raw.fields[5].anchor.occurrence,
      "missing_property",
      "/fields/5/anchor",
    ],
    [
      "an anchor occurrence of all, which one field cannot represent",
      (raw) => (raw.fields[5].anchor.occurrence = "all"),
      "invalid_format",
      "/fields/5/anchor/occurrence",
    ],
    [
      "an anchor occurrence that is neither sole nor an index",
      (raw) => (raw.fields[5].anchor.occurrence = 1.5),
      "invalid_type",
      "/fields/5/anchor/occurrence",
    ],
    [
      "an anchor origin that is not a declared corner",
      (raw) => (raw.fields[5].anchor.origin = "centre"),
      "invalid_format",
      "/fields/5/anchor/origin",
    ],
    ["a zero anchor occurrence", (raw) => (raw.fields[5].anchor.occurrence = 0), "invalid_format", "/fields/5/anchor/occurrence"],
    [
      "a non-finite anchor offset",
      (raw) => (raw.fields[5].anchor.offset.dy = Number.NEGATIVE_INFINITY),
      "coordinate_not_finite",
      "/fields/5/anchor/offset/dy",
    ],
    [
      "an anchor offset missing an axis",
      (raw) => delete raw.fields[5].anchor.offset.dx,
      "missing_property",
      "/fields/5/anchor/offset",
    ],
    [
      "an undeclared anchor placement",
      (raw) => (raw.fields[5].anchor.placement = "nudge"),
      "invalid_format",
      "/fields/5/anchor/placement",
    ],
    [
      "an optional anchor on a required field",
      (raw) => (raw.fields[5].anchor.required = false),
      "anchor_optional_on_required_field",
      "/fields/5/anchor/required",
    ],
    [
      "a 1.1 anchor member in a document that declares 1.0",
      (raw) => {
        raw.schema_version = "1.0";
        delete raw.fields[9].anchor.required;
        raw.fields[5].anchor.placement = "replace";
      },
      "unknown_property",
      "/fields/5/anchor/placement",
    ],
    [
      "a cross-check receipt that disagrees with the declared rectangle",
      (raw) => {
        raw.fields[5].anchor.placement = "cross_check";
        raw.fields[5].anchor.tolerance = 1;
        raw.fields[5].anchor.resolved = {
          document_sha256: "d".repeat(64),
          page: 2,
          occurrence_index: 1,
          anchor_rect: { x: 330, y: 622.4, width: 165.6, height: 12 },
          rect: { x: 390, y: 650, width: 170, height: 36 },
        };
      },
      "anchor_cross_check_failed",
      "/fields/5/anchor/resolved/rect/x",
    ],
    [
      "a cross-check receipt with no tolerance to have passed by",
      (raw) => {
        raw.fields[5].anchor.placement = "cross_check";
        raw.fields[5].anchor.resolved = {
          document_sha256: "d".repeat(64),
          page: 2,
          occurrence_index: 1,
          anchor_rect: { x: 330, y: 622.4, width: 165.6, height: 12 },
          rect: { x: 330, y: 650, width: 170, height: 36 },
        };
      },
      "missing_property",
      "/fields/5/anchor/resolved",
    ],
    [
      "an optional anchor that only cross-checks a rectangle it cannot omit",
      (raw) => {
        raw.fields[9].anchor.placement = "cross_check";
        raw.fields[9].anchor.required = false;
      },
      "invalid_format",
      "/fields/9/anchor/required",
    ],
    [
      "a receipt describing a rectangle the field is not at",
      (raw) => {
        raw.fields[5].anchor.resolved = {
          document_sha256: "c".repeat(64),
          page: 2,
          occurrence_index: 1,
          anchor_rect: { x: 330, y: 622.4, width: 165.6, height: 12 },
          rect: { x: 999, y: 650, width: 170, height: 36 },
        };
      },
      "invalid_format",
      "/fields/5/anchor/resolved/rect/x",
    ],
    [
      "a measured anchor rect with a negative extent",
      (raw) => {
        raw.fields[5].anchor.resolved = {
          document_sha256: "c".repeat(64),
          page: 2,
          occurrence_index: 1,
          anchor_rect: { x: 330, y: 622.4, width: -1, height: 12 },
          rect: { x: 330, y: 650, width: 170, height: 36 },
        };
      },
      "dimension_not_positive",
      "/fields/5/anchor/resolved/anchor_rect/width",
    ],
    [
      "a tolerance on an anchor that decides the position outright",
      (raw) => (raw.fields[5].anchor.tolerance = 2),
      "invalid_format",
      "/fields/5/anchor/tolerance",
    ],
    [
      "a negative cross-check tolerance",
      (raw) => {
        raw.fields[5].anchor.placement = "cross_check";
        raw.fields[5].anchor.tolerance = -1;
      },
      "invalid_format",
      "/fields/5/anchor/tolerance",
    ],
    [
      "a resolution receipt with a digest that is not one",
      (raw) => {
        raw.fields[5].anchor.resolved = {
          document_sha256: "not-a-digest",
          page: 2,
          occurrence_index: 1,
          anchor_rect: { x: 330, y: 622.4, width: 165.6, height: 12 },
          rect: { x: 330, y: 646.9, width: 170, height: 36 },
        };
      },
      "invalid_format",
      "/fields/5/anchor/resolved/document_sha256",
    ],
    [
      "a resolution receipt with a zero occurrence index",
      (raw) => {
        raw.fields[5].anchor.resolved = {
          document_sha256: "a".repeat(64),
          page: 2,
          occurrence_index: 0,
          anchor_rect: { x: 330, y: 622.4, width: 165.6, height: 12 },
          rect: { x: 330, y: 646.9, width: 170, height: 36 },
        };
      },
      "invalid_format",
      "/fields/5/anchor/resolved/occurrence_index",
    ],
    [
      "a resolution receipt missing the digest of the bytes it measured",
      (raw) => {
        raw.fields[5].anchor.resolved = {
          page: 2,
          occurrence_index: 1,
          anchor_rect: { x: 330, y: 622.4, width: 165.6, height: 12 },
          rect: { x: 330, y: 646.9, width: 170, height: 36 },
        };
      },
      "missing_property",
      "/fields/5/anchor/resolved",
    ],
    [
      "a cross-check that only passes before canonical rounding",
      (raw) => {
        raw.fields[5].rect.x = 330.0004;
        raw.fields[5].anchor.placement = "cross_check";
        raw.fields[5].anchor.tolerance = 1.0004;
        raw.fields[5].anchor.resolved = {
          document_sha256: "d".repeat(64),
          page: 2,
          occurrence_index: 1,
          anchor_rect: { x: 330, y: 622.4, width: 165.6, height: 12 },
          rect: { x: 331.00179, y: 650, width: 170, height: 36 },
        };
      },
      "anchor_cross_check_failed",
      "/fields/5/anchor/resolved/rect/x",
    ],
    [
      "a cross-check receipt outside the exact tolerance it states",
      (raw) => {
        raw.fields[5].anchor.placement = "cross_check";
        raw.fields[5].anchor.tolerance = 0;
        raw.fields[5].anchor.resolved = {
          document_sha256: "d".repeat(64),
          page: 2,
          occurrence_index: 1,
          anchor_rect: { x: 330, y: 622.4, width: 165.6, height: 12 },
          rect: { x: 330.001, y: 650, width: 170, height: 36 },
        };
      },
      "anchor_cross_check_failed",
      "/fields/5/anchor/resolved/rect/x",
    ],
    [
      "a measured rect beyond the largest page a PDF can have",
      (raw) => {
        raw.fields[5].anchor.resolved = {
          document_sha256: "d".repeat(64),
          page: 2,
          occurrence_index: 1,
          anchor_rect: { x: 1e20, y: 622.4, width: 165.6, height: 12 },
          rect: raw.fields[5].rect,
        };
      },
      "invalid_format",
      "/fields/5/anchor/resolved/anchor_rect/x",
    ],
    [
      "a tolerance beyond the largest page a PDF can have",
      (raw) => {
        raw.fields[5].anchor.placement = "cross_check";
        raw.fields[5].anchor.tolerance = ANCHOR_TOLERANCE_MAX + 1;
      },
      "invalid_format",
      "/fields/5/anchor/tolerance",
    ],
    [
      "a recipient email that is not an address",
      (raw) => (raw.recipients[0].email = "buyer at example.test"),
      "invalid_email",
      "/recipients/0/email",
    ],
    ["an empty recipient name", (raw) => (raw.recipients[0].name = ""), "invalid_format", "/recipients/0/name"],
    [
      "an undeclared recipient property",
      (raw) => (raw.recipients[0].phone = "+1-555-0100"),
      "unknown_property",
      "/recipients/0/phone",
    ],
    ["recipients that are not an array", (raw) => (raw.recipients = {}), "invalid_type", "/recipients"],
    ["fields that are not an array", (raw) => (raw.fields = {}), "invalid_type", "/fields"],
  ];

  it.each(cases)("rejects %s", (_name, mutate, code, path) => {
    const issues = validateFieldSchema(brokenFixture(mutate));
    const matching = issues.filter((found) => found.code === code && found.path === path);

    expect(matching).toHaveLength(1);
  });

  it("only checks the page count when the page sizes are known", () => {
    const document = brokenFixture((raw) => (raw.fields[0].page = 9));

    expect(validateFieldSchema(document)).toEqual([]);

    const issues = validateFieldSchema(document, { pageSizes: LETTER });

    expect(issues.map((found) => found.code)).toEqual(["page_out_of_range"]);
    expect(issues[0]!.path).toBe("/fields/0/page");
  });

  it("rejects a rect past the page edge", () => {
    const document = brokenFixture((raw) => (raw.fields[0].rect = { x: 500, y: 650, width: 170, height: 36 }));

    expect(validateFieldSchema(document)).toEqual([]);

    const issues = validateFieldSchema(document, { pageSizes: LETTER });

    expect(issues.map((found) => found.code)).toEqual(["rect_out_of_page"]);
    expect(issues[0]!.path).toBe("/fields/0/rect");
  });

  it("accepts a rect flush against the page edge", () => {
    const document = brokenFixture((raw) => (raw.fields[0].rect = { x: 442, y: 756, width: 170, height: 36 }));

    expect(validateFieldSchema(document, { pageSizes: LETTER })).toEqual([]);
  });

  it("checks rects per page against mixed page sizes", () => {
    const pageSizes = [
      { width: 595.276, height: 841.89 },
      { width: 612, height: 1008 },
    ];
    const document = brokenFixture((raw) => {
      raw.fields[1].page = 1;
      raw.fields[1].rect = { x: 500, y: 100, width: 100, height: 24 };
    });

    expect(validateFieldSchema(document, { pageSizes }).map((found) => found.code)).toEqual(["rect_out_of_page"]);
  });

  it("only checks prefill variables when the variable set is known", () => {
    expect(validateFieldSchema(fixture())).toEqual([]);

    const issues = validateFieldSchema(fixture(), { variables: VARIABLES.slice(0, 3) });

    expect(issues.map((found) => found.code)).toEqual(["unresolved_prefill_variable"]);
    expect(issues[0]!.path).toBe("/fields/7/prefill/variable");
  });

  it("resolves nothing against an empty variable set", () => {
    const issues = validateFieldSchema(fixture(), { variables: [] });

    expect(issues).toHaveLength(4);
    expect(issues.every((found) => found.code === "unresolved_prefill_variable")).toBe(true);
  });

  it("reports every problem in one pass", () => {
    const issues = validateFieldSchema(
      brokenFixture((raw) => {
        raw.fields[0].recipient_id = "witness";
        raw.fields[1].type = "radio_group";
        raw.fields[2].rect.width = 0;
        raw.fields[3].page = 0;
      }),
    );

    expect(issues.map((found) => found.code)).toEqual([
      "unknown_recipient",
      "unsupported_field_type",
      "dimension_not_positive",
      "page_out_of_range",
    ]);
  });

  it("skips reference checks when the recipients section is unusable", () => {
    const issues = validateFieldSchema(brokenFixture((raw) => (raw.recipients = "buyer, counterparty")));

    expect(issues.map((found) => found.code)).toEqual(["invalid_type"]);
  });
});
