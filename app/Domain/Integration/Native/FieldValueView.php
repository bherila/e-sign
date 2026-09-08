<?php

declare(strict_types=1);

namespace App\Domain\Integration\Native;

use App\Domain\Preparation\Schema\FieldDefinition;
use App\Domain\Preparation\Schema\FieldType;
use Carbon\CarbonImmutable;

/**
 * One field's value as the API reports it, with signatures described rather than dumped.
 *
 * ## Why a signature is metadata by default
 *
 * A captured signature is an image, routinely tens or hundreds of kilobytes of base64. An
 * envelope has one per signer, so a plain `GET /values` on a two-party agreement would carry
 * half a megabyte of image data to a caller that almost always wants to know *whether* it
 * was signed and *what* was typed in the other fields. Worse, that data then ends up in
 * request logs, proxy caches, and error reports belonging to whoever called.
 *
 * So the default is a description — media type, digest, byte length — which is enough to
 * confirm a signature exists and to verify a copy fetched deliberately. `?include=images`
 * asks for the bytes, and is opt-in precisely so that carrying somebody's signature image
 * around is a decision rather than an accident.
 *
 * The digest is over the **decoded** bytes when the value is a data URL, so it is the digest
 * of the image itself and comparable with anything else that hashed the same image. For an
 * opaque reference there are no image bytes to hash, so the digest is over the reference
 * string and the media type is `application/octet-stream` — described honestly rather than
 * guessed at.
 */
final readonly class FieldValueView
{
    /** Query value that asks for signature bytes rather than a description of them. */
    public const INCLUDE_IMAGES = 'images';

    /**
     * @param  string  $source  `sender`, `recipient`, or `service`.
     * @param  array<string, mixed>|null  $signature  Description of a signature or initials value.
     */
    private function __construct(
        public string $fieldId,
        public FieldType $type,
        public string $recipientId,
        public bool $required,
        public bool $readOnly,
        public string $source,
        public mixed $value,
        public ?array $signature,
        public ?CarbonImmutable $frozenAt,
    ) {}

    /**
     * A value that was stored in `envelope_field_values`.
     */
    public static function stored(
        FieldDefinition $field,
        mixed $value,
        string $source,
        ?CarbonImmutable $frozenAt,
        bool $includeImages,
    ): self {
        $isMark = in_array($field->type, [FieldType::Signature, FieldType::Initials], true);

        return new self(
            fieldId: $field->id,
            type: $field->type,
            recipientId: $field->recipientId,
            required: $field->required,
            readOnly: $field->readOnly,
            source: $source,
            value: $isMark ? null : $value,
            signature: $isMark ? self::describe(is_string($value) ? $value : '', $includeImages) : null,
            frozenAt: $frozenAt,
        );
    }

    /**
     * A value the service supplies from evidence it already holds, never from a submission.
     *
     * Only `signing_date`, which is the owning recipient's attestation instant. It is
     * derived on read rather than copied into `envelope_field_values`, for the reason
     * App\Domain\Signing\Fields\FieldMateriality gives: a value derived from an immutable
     * attestation must not get a second, mutable copy that can disagree with it.
     */
    public static function serviceSupplied(FieldDefinition $field, ?CarbonImmutable $acceptedAt): self
    {
        return new self(
            fieldId: $field->id,
            type: $field->type,
            recipientId: $field->recipientId,
            required: $field->required,
            readOnly: $field->readOnly,
            source: 'service',
            value: $acceptedAt?->toDateString(),
            signature: null,
            frozenAt: null,
        );
    }

    /**
     * @return array{type: string, sha256: string, bytes: int, data?: string}
     */
    private static function describe(string $value, bool $includeImages): array
    {
        $mediaType = 'application/octet-stream';
        $bytes = $value;

        if (preg_match('#^data:([a-z]+/[a-z0-9.+-]+);base64,(.*)$#i', $value, $matches) === 1) {
            $decoded = base64_decode($matches[2], true);

            if ($decoded !== false) {
                $mediaType = strtolower($matches[1]);
                $bytes = $decoded;
            }
        }

        $description = [
            'type' => $mediaType,
            'sha256' => hash('sha256', $bytes),
            'bytes' => strlen($bytes),
        ];

        if ($includeImages) {
            // The stored value verbatim, data URL and all: a caller that asked for the image
            // wants what was captured, not a re-encoding of it.
            $description['data'] = $value;
        }

        return $description;
    }
}
