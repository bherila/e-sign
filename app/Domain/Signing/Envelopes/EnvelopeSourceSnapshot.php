<?php

declare(strict_types=1);

namespace App\Domain\Signing\Envelopes;

use App\Domain\Evidence\Sealing\AssuranceLevel;
use App\Domain\Preparation\Schema\AnchorResolutionGate;
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
 * It is built from a plain array on purpose. The two modules that produce one meet this
 * array shape rather than each other's classes, so neither has to know the other's types.
 * {@see fromTemplateVersion()} maps `TemplateVersion::snapshotForEnvelope()` onto it; the
 * key names differ in two places and translating them here, once, is the whole reason the
 * boundary is an array. The accepted keys are:
 *
 * | Key | Required | Notes |
 * |---|---|---|
 * | `title` | yes | non-empty, at most 255 characters (the profile's own `name` limit) |
 * | `document_revision_id` | yes | an immutable `document_revisions` row; the review revision |
 * | `document_sha256` | yes | must equal that revision's digest, checked by the factory |
 * | `field_schema` | yes | a native field schema 1.0 document, re-validated on the way in |
 * | `consent_policy_version` | yes | the version of the consent text that will be displayed, at most 64 characters |
 * | `assurance_level` | no, default `pades-b-b` | `pades-b-b` or `pades-b-t` |
 * | `signing_mode` | no, default `sequential` | `sequential` or `parallel` |
 * | `render_settings` | no, default `[]` | snapshotted rendering settings |
 * | `source_template_version_id` | no | the template version's **public** ULID; provenance only, no foreign key |
 * | `expiration_hours` | no | at most 8760 (a year); expiry is computed from this at send, never before |
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
     * Column widths, enforced here rather than left to the database.
     *
     * A value too long for its column is a truncation on a permissive engine and an opaque
     * driver error on a strict one. Neither tells the caller which property was wrong, and a
     * truncated title is a silently different agreement name in every notification the
     * recipients receive. 255 is also the `name` limit the Firma profile documents.
     */
    public const MAX_TITLE_LENGTH = 255;

    public const MAX_CONSENT_POLICY_VERSION_LENGTH = 64;

    /** `envelopes.source_template_version_id` is a ULID column, and a ULID is 26 characters. */
    public const MAX_SOURCE_TEMPLATE_VERSION_ID_LENGTH = 26;

    /**
     * A year, and the reason is the column type rather than the workflow.
     *
     * `send()` computes `expires_at` from this, and on MySQL and MariaDB a `TIMESTAMP` runs
     * out in 2038. An unbounded value would therefore make `send()` succeed on SQLite and
     * fail on the production engines — the class of divergence
     * docs/adr/0002-supported-databases.md exists to prevent. An invitation that stays open
     * for more than a year is not a workflow this product has; the profile's own default is
     * seven days.
     */
    public const MAX_EXPIRATION_HOURS = 8_760;

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
        public ?string $sourceTemplateVersionId = null,
        public ?int $expirationHours = self::DEFAULT_EXPIRATION_HOURS,
    ) {}

    /**
     * @param  array<string, mixed>  $snapshot
     *
     * @throws InvalidEnvelopeSnapshot
     */
    public static function fromArray(array $snapshot): self
    {
        $title = self::requiredString($snapshot, 'title', self::MAX_TITLE_LENGTH);
        $consent = self::requiredString(
            $snapshot,
            'consent_policy_version',
            self::MAX_CONSENT_POLICY_VERSION_LENGTH,
        );
        $documentSha256 = self::requiredString($snapshot, 'document_sha256', 64);

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

        // Validity first, then availability — the order the template path uses, and the order that
        // tells the truth. An invalid document is invalid whatever this deployment can do, and
        // answering "wait for anchor resolution" about a document that will still be malformed
        // afterwards sends a caller to wait for something that will not help them.
        //
        // Outside the catch on purpose, and deleted with AnchorResolutionGate when send-time
        // resolution lands: the gate refuses an *option*, and its pointer and code are what a
        // caller branches on. Flattening that into `invalid_field_schema` would leave a sender
        // reading "your snapshot is invalid" with nothing to act on.
        AnchorResolutionGate::assertAvailable($fieldSchema);

        return new self(
            title: $title,
            documentRevisionId: $revisionId,
            documentSha256: $documentSha256,
            fieldSchema: $schema,
            consentPolicyVersion: $consent,
            assuranceLevel: self::assuranceLevel($snapshot),
            signingMode: self::signingMode($snapshot),
            renderSettings: self::renderSettings($snapshot),
            sourceTemplateVersionId: self::nullablePublicId(
                $snapshot,
                'source_template_version_id',
                self::MAX_SOURCE_TEMPLATE_VERSION_ID_LENGTH,
            ),
            expirationHours: self::expirationHours($snapshot),
        );
    }

    /**
     * Map a published template version's snapshot onto this one.
     *
     * `TemplateVersion::snapshotForEnvelope()` names the same facts differently — its
     * `document_revision_sha256` is our `document_sha256`, its `template_version_id` is our
     * `source_template_version_id` — and carries several more for display that an envelope
     * has no column for. Translating in this module rather than asking the templates module
     * to emit our names keeps the dependency pointing one way: Signing knows what a template
     * snapshot looks like, and Preparation knows nothing about envelopes.
     *
     * It also carries no title, no signing mode, no assurance level, and no expiry, because
     * none of those is a property of the template — they are decisions made when the
     * envelope is created. `$overrides` supplies them, and defaults the title to the
     * template's name.
     *
     * @param  array<string, mixed>  $templateSnapshot  From `TemplateVersion::snapshotForEnvelope()`.
     * @param  array<string, mixed>  $overrides  Any key this class accepts; wins over the snapshot.
     *
     * @throws InvalidEnvelopeSnapshot
     */
    public static function fromTemplateVersion(array $templateSnapshot, array $overrides = []): self
    {
        return self::fromArray(array_replace([
            'title' => $templateSnapshot['template_name'] ?? null,
            'source_template_version_id' => $templateSnapshot['template_version_id'] ?? null,
            'document_revision_id' => $templateSnapshot['document_revision_id'] ?? null,
            'document_sha256' => $templateSnapshot['document_revision_sha256'] ?? null,
            'field_schema' => $templateSnapshot['field_schema'] ?? null,
            'consent_policy_version' => $templateSnapshot['consent_policy_version'] ?? null,
            'render_settings' => $templateSnapshot['render_settings'] ?? [],
        ], $overrides));
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
    private static function requiredString(array $snapshot, string $key, int $maxLength): string
    {
        $value = $snapshot[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw InvalidEnvelopeSnapshot::missingProperty($key);
        }

        $value = trim($value);

        if (mb_strlen($value) > $maxLength) {
            throw InvalidEnvelopeSnapshot::invalidProperty(
                $key,
                'it is longer than the '.$maxLength.' character limit.',
            );
        }

        return $value;
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
    private static function nullablePublicId(array $snapshot, string $key, int $maxLength): ?string
    {
        $value = $snapshot[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || mb_strlen($value) > $maxLength) {
            throw InvalidEnvelopeSnapshot::invalidProperty(
                $key,
                'expected a public identifier of at most '.$maxLength.' characters, or null.',
            );
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

        if (! is_int($value) || $value < 1 || $value > self::MAX_EXPIRATION_HOURS) {
            throw InvalidEnvelopeSnapshot::invalidProperty(
                'expiration_hours',
                'expected between 1 and '.self::MAX_EXPIRATION_HOURS.' hours, or null for no expiry.',
            );
        }

        return $value;
    }
}
