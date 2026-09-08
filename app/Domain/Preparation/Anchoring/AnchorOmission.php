<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Anchoring;

use App\Domain\Preparation\Schema\FieldDefinition;

/**
 * A field that was intentionally left out because its optional anchor was not in the document.
 *
 * This is the whole record of the narrow compatibility option in docs/HANDOFF.md section 7: an
 * *optional* field whose anchor declares `required: false` may find no matching text, and the
 * field is then omitted rather than placed at some default position. The option is narrow — it
 * is refused on a required field, and it never excuses an ambiguous anchor — and it is
 * observable: this value is written onto the envelope's own `omitted_anchor_fields` column and
 * into an audit event, so a reader can tell an intentional omission from a field that went
 * missing because something was wrong.
 */
final readonly class AnchorOmission
{
    public function __construct(
        public string $fieldId,
        public string $recipientId,
        public string $fieldType,
        public ?string $alias,
        public int $page,
        public string $anchorText,
        public string $occurrence,
    ) {}

    public static function forField(FieldDefinition $field): self
    {
        $anchor = $field->anchor;

        return new self(
            $field->id,
            $field->recipientId,
            $field->type->value,
            $field->alias,
            $field->page,
            $anchor?->text ?? '',
            $anchor?->occurrence->describe() ?? '',
        );
    }

    /**
     * @return array{field_id: string, recipient_id: string, type: string, alias: string|null, page: int, anchor_text: string, occurrence: string, reason: string}
     */
    public function toArray(): array
    {
        return [
            'field_id' => $this->fieldId,
            'recipient_id' => $this->recipientId,
            'type' => $this->fieldType,
            'alias' => $this->alias,
            'page' => $this->page,
            'anchor_text' => $this->anchorText,
            'occurrence' => $this->occurrence,
            // One reason only. This record exists to say "declared, allowed to be absent, and
            // absent"; anything else about an anchor is a failure, not an omission.
            'reason' => 'optional_anchor_absent',
        ];
    }

    public function describe(): string
    {
        return 'Field "'.$this->fieldId.'" was omitted: its optional anchor "'.$this->anchorText
            .'" does not occur on page '.$this->page.', and the anchor declares that acceptable.';
    }
}
