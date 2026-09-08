import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";

import fixtureJson from "../../../../tests/Fixtures/schema/nda-two-signers.json";
import FieldEditor from "./FieldEditor";
import type { EditorPayload } from "./types";

/**
 * The editor assembled: the composition test the individual unit tests cannot be.
 *
 * PDF.js is mocked, and only PDF.js. Everything else — the reducer, the coordinate module, the
 * schema importer, the table, the inspector, the validation panel — is the real thing, so this
 * catches the class of failure a unit test never does: a page that renders but wires two
 * halves of itself together wrongly.
 *
 * There is no canvas in jsdom and no layout engine, so what is asserted here is the parts that
 * do not need either: what is on the page, what a keyboard does to the document, and that the
 * read-only decision the server made is honoured.
 */

const renderPage = jest.fn<Promise<void>, unknown[]>(() => Promise.resolve());
const destroy = jest.fn<Promise<void>, unknown[]>(() => Promise.resolve());

jest.mock("./pdfjs", () => ({
  __esModule: true,
  loadPdf: jest.fn(() => Promise.resolve({ pageCount: 2, renderPage, destroy })),
  isRenderCancellation: () => false,
}));

/** Two Letter pages, upright, matching the pages the shared NDA fixture places fields on. */
const PAGES = [1, 2].map((page) => ({
  page,
  crop_box: [0, 0, 612, 792],
  rotation: 0,
  user_unit: 1,
  native_width: 612,
  native_height: 792,
}));

function payload(overrides: Partial<EditorPayload> = {}): EditorPayload {
  return {
    workspace: { id: "01JWORKSPACE0000000000000A", name: "Example workspace" },
    template: { id: "01JTEMPLATE00000000000000A", name: "Mutual NDA" },
    version: { id: "01JVERSION000000000000000A", number: 1, status: "draft", published_at: null },
    document: { id: "01JDOCUMENT00000000000000A", title: "Synthetic NDA", revision_id: "r", page_count: 2 },
    urls: {
      document_view: "/workspaces/w/documents/d/revisions/r/view",
      version: "/workspaces/w/templates/t/versions/1",
      schema: "/workspaces/w/templates/t/versions/1/schema.json",
      save: "/workspaces/w/templates/t/versions/1",
      template: "/workspaces/w/templates/t",
    },
    csrf_token: "test-csrf-token",
    read_only: false,
    read_only_reason: null,
    field_schema: JSON.parse(JSON.stringify(fixtureJson)) as unknown,
    field_schema_sha256: "0".repeat(64),
    pages: PAGES,
    variables: [],
    ...overrides,
  };
}

beforeEach(() => {
  renderPage.mockClear();
  destroy.mockClear();
});

describe("opening a draft", () => {
  it("shows the template, the version, and the document it snapshots", () => {
    render(<FieldEditor payload={payload()} />);

    expect(screen.getByRole("heading", { level: 1 })).toHaveTextContent("Mutual NDA v1");
    expect(screen.getByText("draft")).toBeInTheDocument();
    expect(screen.getByText("Synthetic NDA")).toBeInTheDocument();
  });

  it("reports the field set as valid against the page geometry it was given", () => {
    render(<FieldEditor payload={payload()} />);

    expect(screen.getByText("The field set is valid against schema 1.0.")).toHaveAttribute(
      "aria-live",
      "polite",
    );
  });

  it("says that prefill variables were not checked, rather than reporting a pass", () => {
    render(<FieldEditor payload={payload()} />);

    expect(screen.getByText(/Prefill variables were not checked/)).toBeInTheDocument();
  });

  it("lists both recipients with their signing stage and field counts", () => {
    render(<FieldEditor payload={payload()} />);

    const legend = screen.getByRole("region", { name: "Recipients" });

    expect(within(legend).getByText("Example Buyer (Buyer)")).toBeInTheDocument();
    expect(within(legend).getByText("Example Counterparty (Counterparty)")).toBeInTheDocument();
    expect(within(legend).getAllByText("stage 1")).toHaveLength(1);
    expect(within(legend).getAllByText("stage 2")).toHaveLength(1);
  });

  it("draws every field of every page as a focusable, named control", () => {
    render(<FieldEditor payload={payload()} />);

    const box = screen.getByRole("button", {
      name: /Example Buyer: signature, Buyer signature\. Page 2, x 60, y 650/,
    });

    expect(box.tagName).toBe("BUTTON");
    expect(box).toHaveAttribute("data-field-id", "buyer_signature");
  });

  it("asks PDF.js for each page once the document has opened", async () => {
    render(<FieldEditor payload={payload()} />);

    await waitFor(() => expect(renderPage).toHaveBeenCalled());

    expect(renderPage.mock.calls.map((call) => call[0]).sort()).toEqual([1, 2]);
  });
});

describe("keyboard placement", () => {
  it("nudges a field by one point, and by ten with shift", () => {
    render(<FieldEditor payload={payload()} />);

    const box = screen.getByRole("button", { name: /Example Buyer: signature, Buyer signature/ });

    fireEvent.focus(box);
    fireEvent.keyDown(box, { key: "ArrowRight" });

    expect(screen.getByLabelText("x (pt)")).toHaveValue(61);

    fireEvent.keyDown(box, { key: "ArrowDown", shiftKey: true });

    expect(screen.getByLabelText("y (pt)")).toHaveValue(660);
  });

  it("undoes and redoes with the keyboard", () => {
    render(<FieldEditor payload={payload()} />);

    const box = screen.getByRole("button", { name: /Example Buyer: signature, Buyer signature/ });

    fireEvent.focus(box);
    fireEvent.keyDown(box, { key: "ArrowRight" });
    expect(screen.getByLabelText("x (pt)")).toHaveValue(61);

    fireEvent.keyDown(window, { key: "z", ctrlKey: true });
    expect(screen.getByLabelText("x (pt)")).toHaveValue(60);

    fireEvent.keyDown(window, { key: "z", ctrlKey: true, shiftKey: true });
    expect(screen.getByLabelText("x (pt)")).toHaveValue(61);
  });

  it("keeps a nudge on the page rather than off its left edge", () => {
    render(<FieldEditor payload={payload()} />);

    const box = screen.getByRole("button", { name: /Example Buyer: signature, Buyer signature/ });
    fireEvent.focus(box);

    for (let step = 0; step < 8; step += 1) {
      fireEvent.keyDown(box, { key: "ArrowLeft", shiftKey: true });
    }

    expect(screen.getByLabelText("x (pt)")).toHaveValue(0);
  });
});

describe("the two views are peers", () => {
  it("offers the fields table as a tab, with every field in it", () => {
    render(<FieldEditor payload={payload()} />);

    fireEvent.click(screen.getByRole("tab", { name: "Fields table" }));

    expect(screen.getByLabelText("x of buyer_signature (pt)")).toHaveValue(60);
    expect(screen.getByLabelText("Height of counterparty_notes (pt)")).toHaveValue(32);
  });

  it("shows an edit made in the table in the inspector and back on the page", () => {
    render(<FieldEditor payload={payload()} />);

    fireEvent.click(screen.getByRole("tab", { name: "Fields table" }));

    const cell = screen.getByLabelText("x of buyer_signature (pt)");
    // Focus selects the row's field, which is what puts it in the inspector; the inspector and
    // the table are two views of one document, not two documents.
    fireEvent.focus(cell);
    fireEvent.change(cell, { target: { value: "123.5" } });

    expect(screen.getByLabelText("x (pt)")).toHaveValue(123.5);

    fireEvent.click(screen.getByRole("tab", { name: "Pages" }));

    expect(
      screen.getByRole("button", {
        name: /Example Buyer: signature, Buyer signature\. Page 2, x 123.5/,
      }),
    ).toBeInTheDocument();
  });
});

describe("validation", () => {
  it("reports a rectangle pushed off the page, with its pointer and code", () => {
    render(<FieldEditor payload={payload()} />);

    fireEvent.click(screen.getByRole("tab", { name: "Fields table" }));
    fireEvent.change(screen.getByLabelText("Width of buyer_signature (pt)"), {
      target: { value: "900" },
    });

    expect(screen.getByText(/1 problem in the field set/)).toBeInTheDocument();
    expect(screen.getByText(/\/fields\/0\/rect \[rect_out_of_page\]/)).toBeInTheDocument();
  });

  it("reports a zero width as a dimension problem rather than accepting it", () => {
    render(<FieldEditor payload={payload()} />);

    fireEvent.click(screen.getByRole("tab", { name: "Fields table" }));
    fireEvent.change(screen.getByLabelText("Width of buyer_signature (pt)"), {
      target: { value: "0" },
    });

    expect(
      screen.getByText(/\/fields\/0\/rect\/width \[dimension_not_positive\]/),
    ).toBeInTheDocument();
  });
});

describe("read-only", () => {
  it("explains a published version and offers no way to change it", () => {
    render(
      <FieldEditor
        payload={payload({
          read_only: true,
          read_only_reason: "version_published",
          version: {
            id: "01JVERSION000000000000000A",
            number: 1,
            status: "published",
            published_at: "2026-09-08T00:00:00+00:00",
          },
        })}
      />,
    );

    // "Read-only" appears twice on purpose: once as the banner that explains why, once as the
    // toolbar's live status where "Unsaved changes" would otherwise be.
    expect(screen.getAllByText("Read-only")).toHaveLength(2);
    expect(screen.getByText(/This version is published and frozen/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Save/ })).toBeDisabled();
  });

  it("explains an auditor's role without hiding the field set", () => {
    render(
      <FieldEditor payload={payload({ read_only: true, read_only_reason: "insufficient_role" })} />,
    );

    expect(screen.getByText(/can read templates and change none of them/)).toBeInTheDocument();
    expect(
      screen.getByRole("button", { name: /Example Buyer: signature, Buyer signature/ }),
    ).toBeInTheDocument();
  });

  it("does not move a field when a key is pressed on it", () => {
    render(
      <FieldEditor payload={payload({ read_only: true, read_only_reason: "insufficient_role" })} />,
    );

    const box = screen.getByRole("button", { name: /Example Buyer: signature, Buyer signature/ });
    fireEvent.focus(box);
    fireEvent.keyDown(box, { key: "ArrowRight" });

    expect(screen.getByLabelText("x (pt)")).toHaveValue(60);
  });
});

describe("a payload that cannot be opened", () => {
  it("refuses to open rather than showing an empty document, and lists why", () => {
    render(<FieldEditor payload={payload({ field_schema: { schema_version: "9.0" } })} />);

    expect(screen.getByText("This version's field set could not be opened.")).toBeInTheDocument();
    expect(screen.getByText(/schema_version_unsupported/)).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Add field/ })).not.toBeInTheDocument();
  });

  it("refuses a page whose geometry it cannot describe", () => {
    render(
      <FieldEditor payload={payload({ pages: [{ ...PAGES[0]!, rotation: 45 }] })} />,
    );

    expect(screen.getByText(/\/Rotate must be a multiple of 90/)).toBeInTheDocument();
  });
});
