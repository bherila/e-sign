import { fireEvent, render, screen, within } from "@testing-library/react";

import { type FieldSchemaDocument, parseFieldSchema } from "@/schema/fieldSchema";

import fixtureJson from "../../../../tests/Fixtures/schema/nda-two-signers.json";
import type { FieldPatch } from "./editorReducer";
import { FieldTable } from "./FieldTable";

/**
 * The tabular view is the accessible path, so these are accessibility tests as much as
 * behaviour tests: every control is found by its accessible name, never by a test id or a CSS
 * selector, because a control a screen reader cannot name is a control this view does not
 * really offer.
 */

function fixture(): FieldSchemaDocument {
  return parseFieldSchema(JSON.parse(JSON.stringify(fixtureJson)) as unknown);
}

interface Handlers {
  onSelect: jest.Mock<void, [string]>;
  onPatch: jest.Mock<void, [string, FieldPatch]>;
  onDuplicate: jest.Mock<void, [string]>;
  onDelete: jest.Mock<void, [string]>;
}

function renderTable(options: { readOnly?: boolean; document?: FieldSchemaDocument } = {}): Handlers {
  const handlers: Handlers = {
    onSelect: jest.fn(),
    onPatch: jest.fn(),
    onDuplicate: jest.fn(),
    onDelete: jest.fn(),
  };

  render(
    <FieldTable
      document={options.document ?? fixture()}
      pageCount={2}
      selectedFieldId={null}
      readOnly={options.readOnly ?? false}
      issueFieldIds={new Set<string>()}
      {...handlers}
    />,
  );

  return handlers;
}

describe("rendering", () => {
  it("shows one row per field, plus the header row", () => {
    renderTable();

    expect(screen.getAllByRole("row")).toHaveLength(fixture().fields.length + 1);
    expect(screen.getByText("buyer_signature")).toBeInTheDocument();
  });

  it("names every control after the field it belongs to", () => {
    renderTable();

    expect(screen.getByLabelText("x of buyer_signature (pt)")).toHaveValue(60);
    expect(screen.getByLabelText("y of buyer_signature (pt)")).toHaveValue(650);
    expect(screen.getByLabelText("Width of buyer_signature (pt)")).toHaveValue(170);
    expect(screen.getByLabelText("Height of buyer_signature (pt)")).toHaveValue(36);
    expect(screen.getByLabelText("Page of buyer_signature")).toHaveValue(2);
    expect(screen.getByLabelText("Type of buyer_signature")).toHaveValue("signature");
    expect(screen.getByLabelText("Recipient for buyer_signature")).toHaveValue("buyer");
  });

  it("uses real form controls, so the platform provides the keyboard behaviour", () => {
    renderTable();

    for (const name of [
      "x of buyer_signature (pt)",
      "Page of buyer_signature",
      "Label of buyer_signature",
    ]) {
      expect(screen.getByLabelText(name).tagName).toBe("INPUT");
    }

    expect(screen.getByLabelText("Type of buyer_signature").tagName).toBe("SELECT");
  });

  it("renders a fractional coordinate exactly as stored", () => {
    renderTable();

    expect(screen.getByLabelText("x of buyer_initials (pt)")).toHaveValue(505.25);
    expect(screen.getByLabelText("y of buyer_printed_name (pt)")).toHaveValue(694.125);
  });

  it("says so, in text, when a field has validation errors", () => {
    render(
      <FieldTable
        document={fixture()}
        pageCount={2}
        selectedFieldId={null}
        readOnly={false}
        issueFieldIds={new Set(["buyer_signature"])}
        onSelect={jest.fn()}
        onPatch={jest.fn()}
        onDuplicate={jest.fn()}
        onDelete={jest.fn()}
      />,
    );

    expect(screen.getByText("has validation errors")).toBeInTheDocument();
  });
});

describe("numeric editing", () => {
  it("commits a whole number", () => {
    const { onPatch } = renderTable();

    fireEvent.change(screen.getByLabelText("x of buyer_signature (pt)"), { target: { value: "120" } });

    expect(onPatch).toHaveBeenCalledWith("buyer_signature", { rect: { x: 120 } });
  });

  it("commits a fractional number without rounding it away", () => {
    const { onPatch } = renderTable();

    fireEvent.change(screen.getByLabelText("y of buyer_signature (pt)"), {
      target: { value: "650.125" },
    });

    expect(onPatch).toHaveBeenCalledWith("buyer_signature", { rect: { y: 650.125 } });
  });

  it("commits a negative number so the validator can reject it, rather than swallowing it", () => {
    const { onPatch } = renderTable();

    fireEvent.change(screen.getByLabelText("x of buyer_signature (pt)"), { target: { value: "-5" } });

    expect(onPatch).toHaveBeenCalledWith("buyer_signature", { rect: { x: -5 } });
  });

  it("keeps a half-typed value on screen and commits nothing for it", () => {
    const { onPatch } = renderTable();
    const input = screen.getByLabelText("Width of buyer_signature (pt)");

    fireEvent.focus(input);
    fireEvent.change(input, { target: { value: "" } });

    expect(onPatch).not.toHaveBeenCalled();
    expect(input).toHaveValue(null);
  });

  it("restores the committed value when an empty input is left", () => {
    const { onPatch } = renderTable();
    const input = screen.getByLabelText("Width of buyer_signature (pt)");

    fireEvent.focus(input);
    fireEvent.change(input, { target: { value: "" } });
    fireEvent.blur(input);

    expect(onPatch).not.toHaveBeenCalled();
    expect(input).toHaveValue(170);
  });

  it("rounds a page number to an integer, because the schema has no fractional page", () => {
    const { onPatch } = renderTable();

    fireEvent.change(screen.getByLabelText("Page of buyer_signature"), { target: { value: "1.6" } });

    expect(onPatch).toHaveBeenCalledWith("buyer_signature", { page: 2 });
  });

  it("bounds the page input by the pages the document actually has", () => {
    renderTable();

    expect(screen.getByLabelText("Page of buyer_signature")).toHaveAttribute("max", "2");
    expect(screen.getByLabelText("Page of buyer_signature")).toHaveAttribute("min", "1");
  });
});

describe("the rest of a row", () => {
  it("changes the type through the picker", () => {
    const { onPatch } = renderTable();

    fireEvent.change(screen.getByLabelText("Type of buyer_signature"), {
      target: { value: "initials" },
    });

    expect(onPatch).toHaveBeenCalledWith("buyer_signature", { type: "initials" });
  });

  it("reassigns a field to another recipient", () => {
    const { onPatch } = renderTable();

    fireEvent.change(screen.getByLabelText("Recipient for buyer_signature"), {
      target: { value: "counterparty" },
    });

    expect(onPatch).toHaveBeenCalledWith("buyer_signature", { recipient_id: "counterparty" });
  });

  it("edits the label and the prefill variable", () => {
    const { onPatch } = renderTable();

    fireEvent.change(screen.getByLabelText("Label of buyer_signature"), {
      target: { value: "Sign here" },
    });
    fireEvent.change(screen.getByLabelText("Prefill variable of buyer_signature"), {
      target: { value: "recipient.name" },
    });

    expect(onPatch).toHaveBeenCalledWith("buyer_signature", { label: "Sign here" });
    expect(onPatch).toHaveBeenCalledWith("buyer_signature", { prefillVariable: "recipient.name" });
  });

  it("toggles required and read-only", () => {
    const { onPatch } = renderTable();

    fireEvent.click(screen.getByLabelText("buyer_signature is required"));
    fireEvent.click(screen.getByLabelText("buyer_signature is read-only"));

    expect(onPatch).toHaveBeenCalledWith("buyer_signature", { required: false });
    expect(onPatch).toHaveBeenCalledWith("buyer_signature", { read_only: true });
  });

  it("duplicates and deletes", () => {
    const { onDuplicate, onDelete } = renderTable();
    const row = screen.getByText("buyer_signature").closest("tr");

    expect(row).not.toBeNull();

    fireEvent.click(within(row as HTMLElement).getByRole("button", { name: "Duplicate" }));
    fireEvent.click(screen.getByRole("button", { name: "Delete buyer_signature" }));

    expect(onDuplicate).toHaveBeenCalledWith("buyer_signature");
    expect(onDelete).toHaveBeenCalledWith("buyer_signature");
  });

  it("selects the field a control in its row is focused into", () => {
    const { onSelect } = renderTable();

    fireEvent.focus(screen.getByLabelText("x of buyer_initials (pt)"));

    expect(onSelect).toHaveBeenCalledWith("buyer_initials");
  });
});

describe("read-only", () => {
  it("disables every editing control in the table", () => {
    renderTable({ readOnly: true });

    // Every control, not a sample: a single enabled input in a published version is an edit
    // to a frozen field set that the server would then have to refuse with a 409.
    for (const control of [
      ...screen.getAllByRole("textbox"),
      ...screen.getAllByRole("spinbutton"),
      ...screen.getAllByRole("combobox"),
      ...screen.getAllByRole("checkbox"),
      ...screen.getAllByRole("button"),
    ]) {
      // `ui/checkbox` is a Base UI span with `aria-disabled`, not a native control with the
      // `disabled` attribute. Both are "disabled" to a screen reader, and both are what this
      // assertion has to accept.
      const disabled =
        control.hasAttribute("disabled") || control.getAttribute("aria-disabled") === "true";

      expect(`${control.getAttribute("aria-label") ?? control.textContent}: ${disabled}`).toBe(
        `${control.getAttribute("aria-label") ?? control.textContent}: true`,
      );
    }
  });
});

describe("an empty field set", () => {
  it("says an empty draft is legitimate rather than showing a blank table", () => {
    const empty = { ...fixture(), fields: [] };
    renderTable({ document: empty });

    expect(screen.getByText(/An empty field set is a valid draft/)).toBeInTheDocument();
  });
});
