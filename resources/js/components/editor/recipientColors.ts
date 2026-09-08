/**
 * Recipient colours for the overlay and the legend.
 *
 * A fixed palette indexed by the recipient's position in the document, so the colour of a
 * recipient is stable across reloads and across two people looking at the same version — a
 * hash of the id would be stable too but would put two recipients on adjacent hues often
 * enough to matter.
 *
 * Colour is never the only signal. Every field box also carries its recipient's name in its
 * accessible label and its type as visible text, and the table view carries both as columns,
 * so the editor is usable without perceiving the hue at all.
 */

export interface RecipientColor {
  /** Border and handle colour. */
  border: string;
  /** Translucent fill, light enough for the page text to stay readable underneath. */
  fill: string;
  /** Solid swatch for the legend. */
  swatch: string;
  /** Text colour that meets contrast on the swatch. */
  onSwatch: string;
}

/**
 * Eight hues, spaced around the wheel and chosen to stay distinguishable in the common forms
 * of colour vision deficiency: blue, amber, teal, magenta, green, orange, violet, slate.
 */
const PALETTE: RecipientColor[] = [
  { border: "#2563eb", fill: "rgba(37, 99, 235, 0.16)", swatch: "#2563eb", onSwatch: "#ffffff" },
  { border: "#b45309", fill: "rgba(180, 83, 9, 0.16)", swatch: "#b45309", onSwatch: "#ffffff" },
  { border: "#0f766e", fill: "rgba(15, 118, 110, 0.16)", swatch: "#0f766e", onSwatch: "#ffffff" },
  { border: "#a21caf", fill: "rgba(162, 28, 175, 0.16)", swatch: "#a21caf", onSwatch: "#ffffff" },
  { border: "#15803d", fill: "rgba(21, 128, 61, 0.16)", swatch: "#15803d", onSwatch: "#ffffff" },
  { border: "#c2410c", fill: "rgba(194, 65, 12, 0.16)", swatch: "#c2410c", onSwatch: "#ffffff" },
  { border: "#6d28d9", fill: "rgba(109, 40, 217, 0.16)", swatch: "#6d28d9", onSwatch: "#ffffff" },
  { border: "#334155", fill: "rgba(51, 65, 85, 0.16)", swatch: "#334155", onSwatch: "#ffffff" },
];

/** The colour for a field whose `recipient_id` resolves to nobody. Grey, and it is a validation error. */
export const UNKNOWN_RECIPIENT_COLOR: RecipientColor = {
  border: "#71717a",
  fill: "rgba(113, 113, 122, 0.16)",
  swatch: "#71717a",
  onSwatch: "#ffffff",
};

export function recipientColors(recipientIds: string[]): Map<string, RecipientColor> {
  const colors = new Map<string, RecipientColor>();

  recipientIds.forEach((id, index) => {
    colors.set(id, PALETTE[index % PALETTE.length] ?? UNKNOWN_RECIPIENT_COLOR);
  });

  return colors;
}

export function colorFor(colors: Map<string, RecipientColor>, recipientId: string): RecipientColor {
  return colors.get(recipientId) ?? UNKNOWN_RECIPIENT_COLOR;
}
