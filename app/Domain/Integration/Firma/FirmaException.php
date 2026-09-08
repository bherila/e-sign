<?php

declare(strict_types=1);

namespace App\Domain\Integration\Firma;

use RuntimeException;

/**
 * A refusal the facade can put on the wire in the upstream error envelope.
 *
 * Two envelopes, not one, because the pinned document does not use one (disagreement D1):
 * `Error` is `{error, message, details}` while `CreateAndSendValidationError` and
 * `ResendConflictError` carry `code` and no `message`. A single serializer cannot produce
 * both, so this object records which envelope it owes and {@see toArray()} emits that one.
 *
 * Nothing here is App\Domain\Integration\Native\ApiException. The native surface owes its own
 * shape on its own routes and `docs/HANDOFF.md` section 10 is explicit that neither may
 * inherit the other's ("Preserve endpoint-specific response shapes rather than forcing a
 * single convenient serializer on every route").
 */
final class FirmaException extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $details  Rendered under `details` in the `Error` envelope.
     * @param  array<string, mixed>  $extra  Additional top-level members, for the routes
     *                                       whose error schema is not `Error` — currently
     *                                       only `create-and-send`, which owes `code`,
     *                                       `phase`, and `validation_errors`.
     */
    public function __construct(
        public readonly FirmaErrorCode $errorCode,
        string $message,
        public readonly ?array $details = null,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @param  array<string, mixed>|null  $details
     */
    public static function of(FirmaErrorCode $code, string $message, ?array $details = null): self
    {
        return new self($code, $message, $details);
    }

    /**
     * The one 404 this surface returns.
     *
     * `$what` names the kind of thing, never the id: echoing an identifier back confirms its
     * syntax to a caller who has proved nothing about it.
     */
    public static function notFound(string $what): self
    {
        return new self(
            FirmaErrorCode::NotFound,
            'No '.$what.' with that id exists in this workspace.',
        );
    }

    /**
     * An option or route the profile declares and this build will not honour.
     *
     * `$option` is required rather than optional: "unsupported" on its own tells an
     * integrator nothing, and the whole point of failing closed is that the caller learns
     * exactly which instruction was refused.
     *
     * @param  array<string, mixed>  $details
     */
    public static function unsupported(string $option, string $message, array $details = []): self
    {
        return new self(
            FirmaErrorCode::Unsupported,
            $message,
            ['unsupported_option' => $option, 'profile' => FirmaProfile::NAME] + $details,
        );
    }

    /**
     * `create-and-send`'s two-phase validation error.
     *
     * Worth reproducing verbatim: it is how the consumer surfaces a missing prefill to a
     * human, and `phase` is what tells it whether anything was created before the refusal.
     *
     * @param  'create_validation'|'send_validation'  $phase
     * @param  list<array{recipient_index: int, recipient_email: string|null, missing_fields: list<string>}>  $validationErrors
     */
    public static function createAndSendValidation(string $phase, string $message, array $validationErrors): self
    {
        return new self(
            FirmaErrorCode::ValidationFailed,
            $message,
            null,
            [
                // Upstream's own name for the machine token on this route. `message` is
                // absent from this schema and present here anyway, because withholding the
                // sentence would make the body less useful than upstream's for no gain; the
                // required members are all present and correctly named.
                'code' => FirmaErrorCode::ValidationFailed->value,
                'phase' => $phase,
                'validation_errors' => $validationErrors,
            ],
        );
    }

    public function status(): int
    {
        return $this->errorCode->status();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $body = ['error' => $this->errorCode->value, 'message' => $this->getMessage()];

        if ($this->details !== null && $this->details !== []) {
            $body['details'] = $this->details;
        }

        return $body + $this->extra;
    }
}
