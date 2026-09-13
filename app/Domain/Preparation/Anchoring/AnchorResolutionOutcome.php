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
 * anchors is not rewritten and does not get a new field-schema digest for nothing. An envelope
 * whose anchors resolve to what they already said *is* rewritten, with byte-identical content and
 * therefore an identical digest: re-resolution is unconditional, and a receipt is never taken as
 * a reason to skip it.
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
        /**
         * Fields kept for reporting whose disproved receipt was removed.
         *
         * A pass that only cleared a receipt still changed the document, and treating it as
         * unchanged would leave the old receipt in place — the exact claim the pass disproved.
         *
         * @var list<string>
         */
        public array $clearedReceipts = [],
    ) {
        $this->sourceSchemaSha256 = $sourceSchemaSha256 ?? hash('sha256', $schema->canonicalJson());
    }

    public static function unchanged(FieldSchemaDocument $schema): self
    {
        return new self($schema);
    }

    public function changed(): bool
    {
        return $this->resolved !== [] || $this->fieldsOmitted || $this->clearedReceipts !== [];
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
