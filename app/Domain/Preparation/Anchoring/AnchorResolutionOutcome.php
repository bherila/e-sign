<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Anchoring;

use App\Domain\Preparation\Schema\FieldSchemaDocument;

/**
 * What one pass of anchor resolution produced.
 *
 * `$schema` is the document to store: anchored fields carry the rectangle that was resolved and
 * the receipt that produced it, and any intentionally omitted field is gone from `fields`.
 * `$resolved` names the fields whose rectangle this pass wrote, and `$omissions` the fields it
 * removed. `changed()` says whether storing it is necessary at all, so an envelope with no
 * anchors — or one whose anchors were already resolved against these exact bytes — is not
 * rewritten and does not get a new field-schema digest for nothing.
 */
final readonly class AnchorResolutionOutcome
{
    /**
     * Digest of the canonical form of the field set this pass ran *against*.
     *
     * The caller may have resolved before taking a lock on the row it is about to write — the
     * send path does, so a PDF parse does not happen under it — and this is how the outcome is
     * confirmed to belong to the field set that is actually there. It is the input's digest, not
     * the output's; `$schema`'s own digest is in {@see toAuditPayload()}.
     */
    public string $sourceSchemaSha256;

    /**
     * @param  list<string>  $resolved  Field ids whose rectangle this pass wrote.
     * @param  list<AnchorOmission>  $omissions
     */
    public function __construct(
        public FieldSchemaDocument $schema,
        public array $resolved = [],
        public array $omissions = [],
        public bool $fieldsOmitted = false,
        ?string $sourceSchemaSha256 = null,
    ) {
        $this->sourceSchemaSha256 = $sourceSchemaSha256 ?? hash('sha256', $schema->canonicalJson());
    }

    public static function unchanged(FieldSchemaDocument $schema): self
    {
        return new self($schema);
    }

    public function changed(): bool
    {
        return $this->resolved !== [] || $this->fieldsOmitted;
    }

    /**
     * @return list<array{field_id: string, recipient_id: string, type: string, alias: string|null, page: int, anchor_text: string, occurrence: string, reason: string}>
     */
    public function omissionsToArray(): array
    {
        return array_map(static fn (AnchorOmission $omission): array => $omission->toArray(), $this->omissions);
    }

    /**
     * A minimized description for an audit event: what moved, not the whole document.
     *
     * @return array<string, mixed>
     */
    public function toAuditPayload(): array
    {
        return [
            'resolved_field_ids' => $this->resolved,
            'resolved_count' => count($this->resolved),
            'omitted_count' => count($this->omissions),
            'omitted_fields' => $this->omissionsToArray(),
            'omissions_applied' => $this->fieldsOmitted,
            'field_schema_sha256' => hash('sha256', $this->schema->canonicalJson()),
        ];
    }
}
