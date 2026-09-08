<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Schema;

use InvalidArgumentException;

/**
 * One field placed on one page for one recipient.
 *
 * `id` is stable across import and export, and `alias` is the stable template handle, so a
 * template that addresses `buyer_signature_block` keeps working when the document is
 * re-prepared. Neither is ever regenerated on import.
 *
 * `required` defaults to true and `read_only` to false: an unstated requirement fails closed.
 * Both are always written out, so a canonical document states them explicitly.
 */
final readonly class FieldDefinition
{
    public const DEFAULT_REQUIRED = true;

    public const DEFAULT_READ_ONLY = false;

    public function __construct(
        public string $id,
        public string $recipientId,
        public FieldType $type,
        public int $page,
        public Rect $rect,
        public bool $required = self::DEFAULT_REQUIRED,
        public bool $readOnly = self::DEFAULT_READ_ONLY,
        public ?string $label = null,
        public ?string $alias = null,
        public ?Prefill $prefill = null,
        public ?AnchorPlacement $anchor = null,
    ) {
        if ($page < 1) {
            throw new InvalidArgumentException('Page numbers are 1-based; got '.$page.'.');
        }
    }

    public function isAnchored(): bool
    {
        return $this->anchor instanceof AnchorPlacement;
    }

    /**
     * True when this field's anchor still has to be resolved against these exact bytes.
     *
     * A receipt written against a different revision is not reused: it records coordinates
     * measured in a document that is not the one being sent. Re-resolution happens only before
     * send, never after it.
     */
    public function anchorNeedsResolution(string $documentSha256): bool
    {
        return $this->anchor instanceof AnchorPlacement
            && ! $this->anchor->isResolvedAgainst($documentSha256);
    }

    /**
     * The same field with a resolved rectangle and the receipt that produced it.
     *
     * In `cross_check` mode the declared rectangle is authoritative and is kept; only the receipt
     * is attached. In `replace` mode the resolved rectangle becomes the field's rectangle, and
     * from that point the field is indistinguishable from a hand-placed one to everything that
     * reads `rect` — the editor, assembly, signing, and finalization.
     */
    public function withResolvedAnchor(ResolvedAnchorRecord $record): self
    {
        if (! $this->anchor instanceof AnchorPlacement) {
            throw new InvalidArgumentException('Field "'.$this->id.'" has no anchor to resolve.');
        }

        return new self(
            $this->id,
            $this->recipientId,
            $this->type,
            $this->page,
            $this->anchor->replacesRect() ? $record->rect : $this->rect,
            $this->required,
            $this->readOnly,
            $this->label,
            $this->alias,
            $this->prefill,
            $this->anchor->resolvedAs($record),
        );
    }

    /**
     * @param  array<string, mixed>  $field
     */
    public static function fromArray(array $field): self
    {
        /** @var array{id: string, recipient_id: string, type: string, page: int, rect: array{x: int|float, y: int|float, width: int|float, height: int|float}, required?: bool, read_only?: bool, label?: string, alias?: string, prefill?: array{variable: string}, anchor?: array<string, mixed>} $field */
        return new self(
            $field['id'],
            $field['recipient_id'],
            FieldType::from($field['type']),
            (int) $field['page'],
            Rect::fromArray($field['rect']),
            $field['required'] ?? self::DEFAULT_REQUIRED,
            $field['read_only'] ?? self::DEFAULT_READ_ONLY,
            $field['label'] ?? null,
            $field['alias'] ?? null,
            isset($field['prefill']) ? Prefill::fromArray($field['prefill']) : null,
            isset($field['anchor']) ? AnchorPlacement::fromArray($field['anchor']) : null,
        );
    }

    /**
     * Canonical export order: the required properties in schema declaration order, then the
     * optional ones. Order is part of the canonical form; see FieldSchemaDocument::canonicalJson().
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $field = [
            'id' => $this->id,
            'recipient_id' => $this->recipientId,
            'type' => $this->type->value,
            'page' => $this->page,
            'rect' => $this->rect->toArray(),
            'required' => $this->required,
            'read_only' => $this->readOnly,
        ];

        if ($this->label !== null) {
            $field['label'] = $this->label;
        }

        if ($this->alias !== null) {
            $field['alias'] = $this->alias;
        }

        if ($this->prefill instanceof Prefill) {
            $field['prefill'] = $this->prefill->toArray();
        }

        if ($this->anchor instanceof AnchorPlacement) {
            $field['anchor'] = $this->anchor->toArray();
        }

        return $field;
    }
}
