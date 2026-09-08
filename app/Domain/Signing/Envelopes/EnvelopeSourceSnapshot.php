<?php

declare(strict_types=1);

namespace App\Domain\Signing\Envelopes;

use App\Domain\Evidence\Sealing\AssuranceLevel;
use App\Domain\Preparation\Schema\FieldSchemaDocument;
use App\Domain\Preparation\Schema\InvalidFieldSchemaException;
use App\Domain\Signing\Exceptions\InvalidEnvelopeSnapshot;

/**
 * Everything an envelope copies at creation, in one immutable value.
 *
 * This is the module boundary. A template version, the native API, and the Firma facade all
 * produce one of these; the Signing module accepts nothing else and, once the envelope
 * exists, never looks at whatever produced it again. docs/HANDOFF.md section 6: "Sending
 * copies the selected version into the envelope. Later template changes never mutate
 * existing requests."
 *
 * It is built from a plain array on purpose. The templates work (issue #22) exposes
 * `TemplateVersion::snapshotForEnvelope()`, and the two modules meet at this array shape
 * rather than at each other's classes, so neither has to be merged before the other can be
 * written or tested. The accepted keys are:
 *
 * | Key | Required | Notes |
 * |---|---|---|
 * | `title` | yes | non-empty |
 * | `document_revision_id` | yes | an immutable `document_revisions` row; the review revision |
 * | `document_sha256` | yes | must equal that revision's digest, checked by the factory |
 * | `field_schema` | yes | a native field schema 1.0 document, re-validated on the way in |
 * | `consent_policy_version` | yes | the version of the consent text that will be displayed |
 * | `assurance_level` | no, default `pades-b-b` | `pades-b-b` or `pades-b-t` |
 * | `signing_mode` | no, default `sequential` | `sequential` or `parallel` |
 * | `render_settings` | no, default `[]` | snapshotted rendering settings |
 * | `source_template_version_id` | no | provenance only; no foreign key |
 * | `expiration_hours` | no | expiry is computed from this at send, never before |
 *
 * `assurance_level` uses {@see AssuranceLevel}, the Evidence module's enum, rather than a
 * private copy. Its values are `pades-b-b` and `pades-b-t`: one vocabulary for the level an
 * envelope requires and the level an artifact must reach, because two spellings of the same
 * baseline profile is exactly how a requested level quietly becomes a different delivered
 * one.
 */
final readonly class EnvelopeSourceSnapshot
{
    /** Firma's documented default, and a reasonable one: seven days. */
    public const DEFAULT_EXPIRATION_HOURS = 168;

    /**
     * @param  array<string, mixed>  $renderSettings
     */
    public function __construct(
        public string $title,
        public int $documentRevisionId,
        public string $documentSha256,
        public FieldSchemaDocument $fieldSchema,
        public string $consentPolicyVersion,
        public AssuranceLevel $assuranceLevel = AssuranceLevel::PadesBB,
        public SigningMode $signingMode = SigningMode::Sequential,
        public array $renderSettings = [],
        public ?int $sourceTemplateVersionId = null,
        public ?int $expirationHours = self::DEFAULT_EXPIRATION_HOURS,
    ) {}

    /**
     * @param  array<string, mixed>  $snapshot
     *
     * @throws InvalidEnvelopeSnapshot
     */
    public static function fromArray(array $snapshot): self
    {
        $title = self::requiredString($snapshot, 'title');
        $consent = self::requiredString($snapshot, 'consent_policy_version');
        $documentSha256 = self::requiredString($snapshot, 'document_sha256');

        if (preg_match('/^[0-9a-f]{64}$/', $documentSha256) !== 1) {
            throw InvalidEnvelopeSnapshot::invalidProperty(
                'document_sha256',
                'expected 64 lowercase hexadecimal characters.',
            );
        }

        $revisionId = $snapshot['document_revision_id'] ?? null;

        if (! is_int($revisionId) || $revisionId < 1) {
            throw InvalidEnvelopeSnapshot::invalidProperty(
                'document_revision_id',
                'expected the id of an existing document revision.',
            );
        }

        $fieldSchema = $snapshot['field_schema'] ?? null;

        if (! is_array($fieldSchema)) {
            throw InvalidEnvelopeSnapshot::missingProperty('field_schema');
        }

        try {
            // Re-validated rather than trusted. Whatever produced this array validated it
            // once; an envelope that carries a schema it cannot read back is unsignable, and
            // the cheapest place to discover that is before the row exists.
            $schema = FieldSchemaDocument::fromArray($fieldSchema);
        } catch (InvalidFieldSchemaException $e) {
            throw new InvalidEnvelopeSnapshot(
                'The snapshot\'s field schema is not a valid native field schema document: '.$e->getMessage(),
                'invalid_field_schema',
            );
        }

        return new self(
            title: $title,
            documentRevisionId: $revisionId,
            documentSha256: $documentSha256,
            fieldSchema: $schema,
            consentPolicyVersion: $consent,
            assuranceLevel: self::assuranceLevel($snapshot),
            signingMode: self::signingMode($snapshot),
            renderSettings: self::renderSettings($snapshot),
            sourceTemplateVersionId: self::nullableId($snapshot, 'source_template_version_id'),
            expirationHours: self::expirationHours($snapshot),
        );
    }

    /** The digest of the canonical form of the copied schema. */
    public function fieldSchemaSha256(): string
    {
        return hash('sha256', $this->fieldSchema->canonicalJson());
    }

    /**
     * The canonical field schema, as it will be stored.
     *
     * @return array<string, mixed>
     */
    public function canonicalFieldSchema(): array
    {
        return $this->fieldSchema->toArray();
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private static function requiredString(array $snapshot, string $key): string
    {
        $value = $snapshot[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw InvalidEnvelopeSnapshot::missingProperty($key);
        }

        return trim($value);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private static function assuranceLevel(array $snapshot): AssuranceLevel
    {
        $value = $snapshot['assurance_level'] ?? null;

        if ($value === null) {
            return AssuranceLevel::PadesBB;
        }

        if ($value instanceof AssuranceLevel) {
            return $value;
        }

        // No downgrade on an unrecognised level. docs/HANDOFF.md section 9: a requested
        // assurance level that cannot be met is an error, never a silent lesser one.
        $level = is_string($value) ? AssuranceLevel::tryFrom($value) : null;

        if ($level === null) {
            throw InvalidEnvelopeSnapshot::invalidProperty(
                'assurance_level',
                'expected one of pades-b-b, pades-b-t.',
            );
        }

        return $level;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private static function signingMode(array $snapshot): SigningMode
    {
        $value = $snapshot['signing_mode'] ?? null;

        if ($value === null) {
            return SigningMode::Sequential;
        }

        if ($value instanceof SigningMode) {
            return $value;
        }

        $mode = is_string($value) ? SigningMode::tryFrom($value) : null;

        if ($mode === null) {
            throw InvalidEnvelopeSnapshot::invalidProperty(
                'signing_mode',
                'expected one of '.implode(', ', SigningMode::values()).'.',
            );
        }

        return $mode;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private static function renderSettings(array $snapshot): array
    {
        $value = $snapshot['render_settings'] ?? [];

        if (! is_array($value)) {
            throw InvalidEnvelopeSnapshot::invalidProperty('render_settings', 'expected an object.');
        }

        /** @var array<string, mixed> */
        return $value;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private static function nullableId(array $snapshot, string $key): ?int
    {
        $value = $snapshot[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_int($value) || $value < 1) {
            throw InvalidEnvelopeSnapshot::invalidProperty($key, 'expected a positive integer id or null.');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private static function expirationHours(array $snapshot): ?int
    {
        if (! array_key_exists('expiration_hours', $snapshot)) {
            return self::DEFAULT_EXPIRATION_HOURS;
        }

        $value = $snapshot['expiration_hours'];

        // Explicit null means "no expiry", which is different from "unspecified".
        if ($value === null) {
            return null;
        }

        if (! is_int($value) || $value < 1) {
            throw InvalidEnvelopeSnapshot::invalidProperty(
                'expiration_hours',
                'expected a positive number of hours, or null for no expiry.',
            );
        }

        return $value;
    }
}
