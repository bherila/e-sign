<?php

declare(strict_types=1);

namespace App\Domain\Integration\Native;

/**
 * What `POST /api/v1/envelopes` asks for, once the Form Request has validated the shape.
 *
 * Two mutually exclusive sources — a published template version, or a document plus a field
 * schema — and the decisions that are not properties of either: who the parties actually
 * are, what is prefilled, and the assurance, mode and expiry the sender wants.
 *
 * A value object rather than an array so the service's signature says what it accepts, and
 * so the Firma facade builds the same thing rather than reproducing the mapping.
 */
final readonly class NewEnvelope
{
    /**
     * @param  array<string, mixed>|null  $fieldSchema  Native field schema 1.0, with `document_id`.
     * @param  list<array{id: string, name?: string|null, email?: string|null}>  $recipients
     *                                                                                        Contact details for schema recipient ids.
     * @param  array<string, mixed>  $values  Sender prefills: schema field id => value.
     * @param  bool  $expirySpecified  Whether `expires_in_hours` was present at all.
     * @param  bool|null  $requireOtp  Whether this envelope's guests answer a mailed code as
     *                                 well as following the link. **Null is not false**: it
     *                                 means "not decided here", so the envelope inherits its
     *                                 workspace and then the deployment default
     *                                 (App\Domain\Signing\Sessions\OtpRequirement). An
     *                                 explicit false overrules both, which is a different
     *                                 instruction from saying nothing.
     */
    public function __construct(
        public ?string $templateVersionId = null,
        public ?string $documentId = null,
        public ?array $fieldSchema = null,
        public ?string $title = null,
        public array $recipients = [],
        public array $values = [],
        public ?string $assuranceLevel = null,
        public ?string $signingMode = null,
        public ?int $expiresInHours = null,
        public ?string $consentPolicyVersion = null,
        public bool $expirySpecified = false,
        public ?bool $requireOtp = null,
    ) {}

    /**
     * @param  array<string, mixed>  $input  Validated request data.
     */
    public static function fromValidated(array $input): self
    {
        /** @var list<array{id: string, name?: string|null, email?: string|null}> $recipients */
        $recipients = array_values(array_map(
            static fn (array $recipient): array => [
                'id' => (string) ($recipient['id'] ?? $recipient['schema_recipient_id'] ?? ''),
                'name' => isset($recipient['name']) ? (string) $recipient['name'] : null,
                'email' => isset($recipient['email']) ? (string) $recipient['email'] : null,
            ],
            is_array($input['recipients'] ?? null) ? $input['recipients'] : [],
        ));

        return new self(
            templateVersionId: self::nullableString($input, 'template_version_id'),
            documentId: self::nullableString($input, 'document_id'),
            fieldSchema: is_array($input['field_schema'] ?? null) ? $input['field_schema'] : null,
            title: self::nullableString($input, 'title'),
            recipients: $recipients,
            values: is_array($input['values'] ?? null) ? $input['values'] : [],
            assuranceLevel: self::nullableString($input, 'assurance_level'),
            signingMode: self::nullableString($input, 'signing_mode'),
            expiresInHours: array_key_exists('expires_in_hours', $input) && $input['expires_in_hours'] !== null
                ? (int) $input['expires_in_hours']
                : null,
            consentPolicyVersion: self::nullableString($input, 'consent_policy_version'),
            // An explicit `null` is "this envelope never expires", which is a different
            // instruction from omitting the key (take the seven-day default). Only the
            // presence of the key distinguishes them.
            expirySpecified: array_key_exists('expires_in_hours', $input),
            requireOtp: is_bool($input['require_otp'] ?? null) ? $input['require_otp'] : null,
        );
    }

    public function fromTemplate(): bool
    {
        return $this->templateVersionId !== null;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private static function nullableString(array $input, string $key): ?string
    {
        $value = $input[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
