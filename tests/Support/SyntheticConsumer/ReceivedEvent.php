<?php

declare(strict_types=1);

namespace Tests\Support\SyntheticConsumer;

/**
 * One POST as the consumer's receiver saw it.
 *
 * An *attempt*, not an event: a retry of the same event produces a second one of these with
 * the same `eventId`, a new `attemptId`, a new `attempt` number and a fresh
 * `signatureTimestamp`. That is the whole shape of the reliability contract, so it is what
 * the record keeps.
 */
final readonly class ReceivedEvent
{
    /**
     * @param  array<string, mixed>  $payload  The decoded `data` object, or `[]` when the
     *                                         body did not verify and was never decoded.
     */
    public function __construct(
        public string $eventId,
        public string $type,
        public string $attemptId,
        public int $attempt,
        public ?int $signatureTimestamp,
        public string $occurredAt,
        public array $payload,
        public string $rawBody,
        public bool $verified,
        public int $status,
        public bool $duplicate = false,
        public bool $regressive = false,
        public bool $verifiedWithPreviousSecret = false,
    ) {}

    /** The signing request this event is about, or null for an event that names none. */
    public function signingRequestId(): ?string
    {
        $id = $this->payload['signing_request']['id'] ?? null;

        return is_string($id) ? $id : null;
    }

    /**
     * The recipient a recipient-scoped event is about.
     *
     * `data.recipients[]` carries exactly one entry for `signing_request.recipient.*` and the
     * whole list for every other event, which is the shape
     * `App\Domain\Delivery\Events\SigningRequestPayload` documents. Reading `[0]` is
     * therefore right for the recipient events and meaningless for the rest; only the
     * recipient events ask.
     */
    public function recipientEmail(): ?string
    {
        $email = $this->payload['recipients'][0]['email'] ?? null;

        return is_string($email) ? $email : null;
    }

    /** Whether the event body says a validated artifact exists. */
    public function reportsDownloadAvailable(): bool
    {
        return ($this->payload['signing_request']['download']['available'] ?? false) === true;
    }

    /**
     * @return array<string, bool>
     */
    public function status(): array
    {
        $status = $this->payload['signing_request']['status'] ?? [];

        return is_array($status) ? $status : [];
    }
}
