import { Badge } from "@/components/ui/badge";
import type { FieldSchemaDocument } from "@/schema/fieldSchema";

import { colorFor, type RecipientColor } from "./recipientColors";

/**
 * Who is who, and in what order they sign.
 *
 * The legend is what makes the overlay's colours mean something, and it states the signing
 * stage next to each recipient because "buyer then counterparty" is a different agreement from
 * "buyer and counterparty at once" and the boxes on the page cannot show the difference.
 *
 * The swatch is decorative: every entry names the recipient in text, and every field box names
 * its recipient in its accessible name, so nothing here is conveyed by colour alone.
 */
export interface RecipientLegendProps {
  document: FieldSchemaDocument;
  colors: Map<string, RecipientColor>;
  /** Number of fields bound to each recipient id. */
  fieldCounts: Map<string, number>;
}

export function RecipientLegend({ document, colors, fieldCounts }: RecipientLegendProps) {
  const stages = new Map<string, number>();

  document.signing_order.forEach((stage, index) => {
    for (const id of stage) {
      stages.set(id, index + 1);
    }
  });

  return (
    <section aria-labelledby="editor-recipients" className="flex flex-col gap-2">
      <h2 id="editor-recipients" className="text-sm font-semibold">
        Recipients
      </h2>

      <ul className="flex flex-col gap-1.5">
        {document.recipients.map((recipient) => {
          const color = colorFor(colors, recipient.id);
          const stage = stages.get(recipient.id);

          return (
            <li key={recipient.id} className="flex items-center gap-2 text-sm">
              <span
                aria-hidden="true"
                className="size-3 shrink-0 rounded-[3px]"
                style={{ backgroundColor: color.swatch }}
              />
              <span className="min-w-0 flex-1 truncate">
                {recipient.name}
                {recipient.role === undefined ? "" : ` (${recipient.role})`}
              </span>
              <Badge variant="outline" className="tabular-nums">
                {stage === undefined ? "not signing" : `stage ${stage}`}
              </Badge>
              <Badge variant="secondary" className="tabular-nums">
                {fieldCounts.get(recipient.id) ?? 0} fields
              </Badge>
            </li>
          );
        })}
      </ul>

      {document.recipients.length === 0 ? (
        <p className="text-muted-foreground text-sm">
          This version declares no recipients, which the schema rejects. Import a document that
          declares them, or add them through the template version API.
        </p>
      ) : null}
    </section>
  );
}
