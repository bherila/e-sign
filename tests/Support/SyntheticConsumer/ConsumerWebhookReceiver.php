<?php

declare(strict_types=1);

namespace Tests\Support\SyntheticConsumer;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The consumer's inbox: the thing on the far end of a webhook endpoint.
 *
 * It is a route, not a mock. {@see SyntheticConsumer} registers it at
 * `POST /__consumer/webhook` and every delivery this application makes arrives here through
 * the real HTTP kernel, with the real headers and the exact bytes the signer signed. What it
 * does with them is what `docs/HANDOFF.md` §11 asks a consumer to do:
 *
 * - verify `X-Firma-Signature` over the **raw body** with an independent implementation
 *   ({@see FirmaSignatureVerifier} — nothing here imports our signer);
 * - enforce a replay window, and answer a signature it cannot verify with **400**, never 500,
 *   because a 500 asks for a retry of a request that can never succeed;
 * - keep a durable inbox keyed on the normalised event identity, so a duplicate delivery is
 *   acknowledged and processed once;
 * - refuse to regress: an event that describes an older moment than one already applied to
 *   the same signing request is acknowledged and ignored, because deliveries can arrive out
 *   of order and `created_at` is the only ordering that survives a retry.
 *
 * **Replay protection inside the window is the receiver's, and this is the receiver.**
 * `docs/delivery/webhooks.md` says so explicitly; the ledger below is what that sentence
 * means in code.
 *
 * The failure modes are here rather than in the test so a scenario can say
 * `$receiver->failNext(500)` and read like the outage it describes.
 */
final class ConsumerWebhookReceiver
{
    /**
     * Every POST that arrived, verified or not, in arrival order.
     *
     * @var list<ReceivedEvent>
     */
    private array $attempts = [];

    /**
     * The durable inbox: one entry per logical event, first acceptance wins.
     *
     * @var array<string, ReceivedEvent>
     */
    private array $ledger = [];

    /**
     * The latest `created_at` applied per signing request, for the regression guard.
     *
     * @var array<string, string>
     */
    private array $watermark = [];

    /**
     * Secrets the receiver currently accepts. More than one only during a rotation.
     *
     * @var list<string>
     */
    private array $secrets;

    /**
     * Statuses to answer with, consumed one per request.
     *
     * @var list<int>
     */
    private array $plannedStatuses = [];

    /**
     * Listeners keyed by event type, run once per newly processed event.
     *
     * @var array<string, list<Closure(ReceivedEvent): void>>
     */
    private array $listeners = [];

    private int $rejected = 0;

    /**
     * @param  list<string>  $secrets
     */
    public function __construct(array $secrets)
    {
        $this->secrets = array_values($secrets);
    }

    /* --------------------------------------------------------------- configuration */

    /** Reconfigure the receiver, as an operator would during a rotation overlap. */
    public function acceptSecrets(string ...$secrets): void
    {
        $this->secrets = array_values($secrets);
    }

    /**
     * @return list<string>
     */
    public function secrets(): array
    {
        return $this->secrets;
    }

    /** Answer the next `$times` requests with `$status` instead of processing them. */
    public function failNext(int $status, int $times = 1): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->plannedStatuses[] = $status;
        }
    }

    /**
     * Run `$handler` the first time an event of `$type` is processed.
     *
     * This is where a test puts the assertions that are only meaningful *at the moment of
     * receipt* — "was the artifact already published when `completed` arrived" cannot be
     * answered after the fact, because by then it is published either way.
     *
     * @param  Closure(ReceivedEvent): void  $handler
     */
    public function on(string $type, Closure $handler): void
    {
        $this->listeners[$type][] = $handler;
    }

    /* --------------------------------------------------------------------- the route */

    public function receive(Request $request): Response
    {
        $raw = $request->getContent();
        $header = (string) $request->header('X-Firma-Signature', '');

        $verified = FirmaSignatureVerifier::verify($header, $raw, $this->secrets, time());
        [$timestamp] = FirmaSignatureVerifier::parse($header);

        if (! $verified) {
            $this->rejected++;
            $this->attempts[] = $this->record($request, $raw, $timestamp, [], false, 400);

            // 400, not 500: a signature that does not verify will not verify on a retry.
            return new Response('{"error":"signature"}', 400, ['Content-Type' => 'application/json']);
        }

        $planned = array_shift($this->plannedStatuses);

        if ($planned !== null && ($planned < 200 || $planned >= 300)) {
            $this->attempts[] = $this->record($request, $raw, $timestamp, [], true, $planned);

            return new Response('{"error":"planned"}', $planned, ['Content-Type' => 'application/json']);
        }

        /** @var array<string, mixed> $envelope */
        $envelope = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);

        $eventId = self::normalisedEventId($envelope);
        $duplicate = array_key_exists($eventId, $this->ledger);
        $regressive = ! $duplicate && $this->regresses($envelope);

        $event = $this->record(
            $request,
            $raw,
            $timestamp,
            is_array($envelope['data'] ?? null) ? $envelope['data'] : [],
            true,
            200,
            $duplicate,
            $regressive,
            $envelope,
        );

        $this->attempts[] = $event;

        if ($duplicate || $regressive) {
            // Acknowledged, deliberately. Answering anything else would make the sender
            // retry an event this inbox has already dealt with.
            return new Response('{"status":"duplicate"}', 200, ['Content-Type' => 'application/json']);
        }

        $this->ledger[$eventId] = $event;
        $this->advanceWatermark($event);

        foreach ($this->listeners[$event->type] ?? [] as $listener) {
            $listener($event);
        }

        return new Response('{"status":"ok"}', 200, ['Content-Type' => 'application/json']);
    }

    /* ------------------------------------------------------------------ what it saw */

    /**
     * Every POST, including the ones it refused.
     *
     * @return list<ReceivedEvent>
     */
    public function attempts(?string $type = null): array
    {
        return $this->filter($this->attempts, $type);
    }

    /**
     * The inbox: one entry per logical event, in the order each was first processed.
     *
     * @return list<ReceivedEvent>
     */
    public function processed(?string $type = null): array
    {
        return $this->filter(array_values($this->ledger), $type);
    }

    /**
     * @return list<string>
     */
    public function processedTypes(): array
    {
        return array_map(static fn (ReceivedEvent $e): string => $e->type, array_values($this->ledger));
    }

    public function firstProcessed(string $type): ?ReceivedEvent
    {
        return $this->processed($type)[0] ?? null;
    }

    public function countProcessed(string $type): int
    {
        return count($this->processed($type));
    }

    public function rejectedCount(): int
    {
        return $this->rejected;
    }

    /**
     * @param  list<ReceivedEvent>  $events
     * @return list<ReceivedEvent>
     */
    private function filter(array $events, ?string $type): array
    {
        if ($type === null) {
            return $events;
        }

        return array_values(array_filter($events, static fn (ReceivedEvent $e): bool => $e->type === $type));
    }

    /* --------------------------------------------------------------------- internals */

    /**
     * The consumer's normalised event identity.
     *
     * `docs/compatibility/firma-capability-matrix.md` disagreement D13: the consumer's own
     * resolver reads `event_id` first and falls back to a payload hash. We emit `id`, the
     * name the upstream envelope uses, so the consumer normalises the two names — and falls
     * back to hashing the body when neither is present, which is the behaviour that keeps a
     * nameless event from being processed twice.
     *
     * @param  array<string, mixed>  $envelope
     */
    private static function normalisedEventId(array $envelope): string
    {
        foreach (['event_id', 'id'] as $key) {
            $value = $envelope[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return 'sha256:'.hash('sha256', json_encode($envelope, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function regresses(array $envelope): bool
    {
        $data = $envelope['data'] ?? [];
        $id = is_array($data) ? ($data['signing_request']['id'] ?? null) : null;
        $createdAt = $envelope['created_at'] ?? null;

        if (! is_string($id) || ! is_string($createdAt)) {
            return false;
        }

        $seen = $this->watermark[$id] ?? null;

        return $seen !== null && strtotime($createdAt) < strtotime($seen);
    }

    private function advanceWatermark(ReceivedEvent $event): void
    {
        $id = $event->signingRequestId();

        if ($id === null || $event->occurredAt === '') {
            return;
        }

        $seen = $this->watermark[$id] ?? null;

        if ($seen === null || strtotime($event->occurredAt) >= strtotime($seen)) {
            $this->watermark[$id] = $event->occurredAt;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $envelope
     */
    private function record(
        Request $request,
        string $raw,
        ?int $timestamp,
        array $payload,
        bool $verified,
        int $status,
        bool $duplicate = false,
        bool $regressive = false,
        ?array $envelope = null,
    ): ReceivedEvent {
        return new ReceivedEvent(
            eventId: (string) $request->header('X-Esign-Event-Id', ''),
            type: (string) $request->header('X-Firma-Event', ''),
            attemptId: (string) $request->header('X-Firma-Delivery', ''),
            attempt: (int) $request->header('X-Esign-Attempt', '0'),
            signatureTimestamp: $timestamp,
            occurredAt: is_string($envelope['created_at'] ?? null) ? $envelope['created_at'] : '',
            payload: $payload,
            rawBody: $raw,
            verified: $verified,
            status: $status,
            duplicate: $duplicate,
            regressive: $regressive,
            verifiedWithPreviousSecret: $verified
                && count($this->secrets) > 1
                && ! FirmaSignatureVerifier::verify(
                    (string) $request->header('X-Firma-Signature', ''),
                    $raw,
                    [$this->secrets[0]],
                    time(),
                ),
        );
    }
}
