<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Schema;

use JsonException;

/**
 * A native field definition document: the one versioned shape the visual editor and the native
 * API both speak (docs/HANDOFF.md section 7).
 *
 * The JSON contract is `resources/schema/field-schema-1.0.json`; this is its PHP projection, and
 * `resources/js/schema/fieldSchema.ts` is its TypeScript projection. Immutable value objects all
 * the way down.
 *
 * ## Round trip
 *
 * `fromArray()` refuses anything the schema does not allow — there is no partial import — and
 * `toArray()` writes the document back in canonical form:
 *
 * - Properties in schema declaration order, at every level. Deterministic, and it keeps the
 *   emitted document readable in the same shape as the schema file and the handoff example
 *   rather than alphabetised into `coordinate_space, document_id, fields, ...`.
 * - `required` and `read_only` always stated, `anchor.occurrence` always stated.
 * - Coordinates rounded once to three decimals and written as JSON integers when integral
 *   ({@see CanonicalNumber}).
 *
 * For a document already in canonical form the round trip is exact:
 * `$document->toArray() === $canonical` including key order, and `canonicalJson()` reproduces the
 * bytes. For a document that merely *validates* — defaults omitted, `60.0` for `60`, coordinates
 * finer than a thousandth of a point — the first import canonicalises, and every round trip after
 * that is byte-identical. `canonicalJson()` is idempotent, and that is the property the tests pin.
 */
final readonly class FieldSchemaDocument
{
    /**
     * Encoding flags for the canonical form.
     *
     * Unescaped slashes and unescaped unicode make this byte-identical to `JSON.stringify` in
     * the editor. `JSON_PRESERVE_ZERO_FRACTION` is deliberately absent: an integral coordinate
     * is written `60`, not `60.0`.
     */
    public const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /**
     * @param  list<Recipient>  $recipients
     * @param  list<list<string>>  $signingOrder  Sequential stages of parallel recipient ids.
     * @param  list<FieldDefinition>  $fields
     */
    public function __construct(
        public SchemaVersion $schemaVersion,
        public string $documentId,
        public CoordinateSpaceDeclaration $coordinateSpace,
        public array $recipients,
        public array $signingOrder,
        public array $fields,
    ) {}

    /**
     * Import a decoded document.
     *
     * @param  array<string, mixed>  $document
     *
     * @throws InvalidFieldSchemaException With every structural error found, not just the first.
     */
    public static function fromArray(array $document): self
    {
        $result = (new FieldSchemaValidator)->validate($document);

        if ($result->hasErrors()) {
            throw new InvalidFieldSchemaException($result);
        }

        /** @var array{schema_version: string, document_id: string, coordinate_space: array{unit: string, origin: string, page_box: string, rotation: string, page_index_base: int}, recipients: list<array{id: string, name: string, email: string, role?: string}>, fields: list<array<string, mixed>>, signing_order: list<list<string>>} $document */
        $version = SchemaVersion::parse($document['schema_version']);

        return new self(
            $version ?? SchemaVersion::current(),
            $document['document_id'],
            CoordinateSpaceDeclaration::fromArray($document['coordinate_space']),
            array_map(
                static fn (array $recipient): Recipient => Recipient::fromArray($recipient),
                array_values($document['recipients']),
            ),
            array_map(
                static fn (array $stage): array => array_values($stage),
                array_values($document['signing_order']),
            ),
            array_map(
                static fn (array $field): FieldDefinition => FieldDefinition::fromArray($field),
                array_values($document['fields']),
            ),
        );
    }

    /**
     * Import a JSON document.
     *
     * @throws InvalidFieldSchemaException When the JSON is not an object, or the document is invalid.
     */
    public static function fromJson(string $json): self
    {
        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidFieldSchemaException(new ValidationResult([
                new ValidationError('', ValidationCode::InvalidType, 'Document is not valid JSON: '.$e->getMessage()),
            ]));
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new InvalidFieldSchemaException(new ValidationResult([
                new ValidationError('', ValidationCode::InvalidType, 'Document must be a JSON object.'),
            ]));
        }

        return self::fromArray($decoded);
    }

    /**
     * Export in canonical form. Key order is part of the contract; see the class docblock.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion->toString(),
            'document_id' => $this->documentId,
            'coordinate_space' => $this->coordinateSpace->toArray(),
            'recipients' => array_map(
                static fn (Recipient $recipient): array => $recipient->toArray(),
                $this->recipients,
            ),
            'signing_order' => $this->signingOrder,
            'fields' => array_map(
                static fn (FieldDefinition $field): array => $field->toArray(),
                $this->fields,
            ),
        ];
    }

    /**
     * Deterministic JSON for storage, hashing, and diffing: fixed key order, fixed numeric
     * spelling, no insignificant whitespace. The same document always produces the same bytes,
     * in PHP and in the TypeScript editor.
     *
     * @throws JsonException Never in practice: every value is a scalar, list, or map of them.
     */
    public function canonicalJson(): string
    {
        return json_encode($this->toArray(), self::JSON_FLAGS);
    }

    /**
     * The same document with a different field set: everything else is carried over verbatim.
     *
     * Anchor resolution is the only caller. It rewrites fields — a resolved rectangle onto an
     * anchored field, or an intentionally omitted optional field removed — and must not be able
     * to touch the recipients, the signing order, or the declared coordinate space while doing
     * it. Passing a whole new document would make that a matter of care; this makes it
     * structural.
     *
     * @param  list<FieldDefinition>  $fields
     */
    public function withFields(array $fields): self
    {
        return new self(
            $this->schemaVersion,
            $this->documentId,
            $this->coordinateSpace,
            $this->recipients,
            $this->signingOrder,
            array_values($fields),
        );
    }

    /**
     * @return list<FieldDefinition>
     */
    public function anchoredFields(): array
    {
        return array_values(array_filter(
            $this->fields,
            static fn (FieldDefinition $field): bool => $field->isAnchored(),
        ));
    }

    /**
     * @return list<string>
     */
    public function recipientIds(): array
    {
        return array_map(static fn (Recipient $recipient): string => $recipient->id, $this->recipients);
    }

    public function recipient(string $id): ?Recipient
    {
        foreach ($this->recipients as $recipient) {
            if ($recipient->id === $id) {
                return $recipient;
            }
        }

        return null;
    }

    public function field(string $id): ?FieldDefinition
    {
        foreach ($this->fields as $field) {
            if ($field->id === $id) {
                return $field;
            }
        }

        return null;
    }

    /** Look a field up by its stable template alias. */
    public function fieldByAlias(string $alias): ?FieldDefinition
    {
        foreach ($this->fields as $field) {
            if ($field->alias === $alias) {
                return $field;
            }
        }

        return null;
    }

    /**
     * @return list<FieldDefinition>
     */
    public function fieldsFor(string $recipientId): array
    {
        return array_values(array_filter(
            $this->fields,
            static fn (FieldDefinition $field): bool => $field->recipientId === $recipientId,
        ));
    }

    /**
     * @return list<FieldDefinition>
     */
    public function fieldsOnPage(int $page): array
    {
        return array_values(array_filter(
            $this->fields,
            static fn (FieldDefinition $field): bool => $field->page === $page,
        ));
    }

    /** Highest page number any field is placed on, or 0 when there are no fields. */
    public function highestPage(): int
    {
        $highest = 0;

        foreach ($this->fields as $field) {
            $highest = max($highest, $field->page);
        }

        return $highest;
    }

    /** 1-based signing stage a recipient belongs to, or null when they are in none. */
    public function stageOf(string $recipientId): ?int
    {
        foreach ($this->signingOrder as $index => $stage) {
            if (in_array($recipientId, $stage, true)) {
                return $index + 1;
            }
        }

        return null;
    }
}
