<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Webhooks\Jobs;

use App\Domain\Delivery\Outbound\Exceptions\DestinationRefusedException;
use App\Domain\Delivery\Webhooks\DeliveryState;
use App\Domain\Delivery\Webhooks\Models\WebhookDelivery;
use App\Domain\Delivery\Webhooks\TextRedactor;
use App\Domain\Delivery\Webhooks\WebhookDispatcher;
use App\Domain\Delivery\Webhooks\WebhookSigner;
use App\Domain\Delivery\Webhooks\WebhookTransport;
use App\Domain\Evidence\Retention\RestoreDrill;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;

/**
 * One attempt to deliver one event to one endpoint.
 *
 * Retries are scheduled by this job as new attempt rows, not by the queue
 * worker: `$tries = 1` on purpose. Queue-level retries would re-run an attempt
 * that already recorded its outcome, producing a duplicate POST with no row to
 * show for it and no way to reason about the schedule.
 *
 * Nothing here logs a secret. The signing secrets never leave the header they
 * are computed into, and every string that comes back from the receiver — body
 * excerpt and transport error alike — goes through the redactor before it is
 * stored, because a receiver's error page routinely echoes the request headers
 * it did not like.
 */
final class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    /**
     * Retries are this job's own business; see the class docblock.
     */
    public int $tries = 1;

    public function __construct(public readonly int $webhookDeliveryId) {}

    public function handle(
        WebhookTransport $transport,
        WebhookDispatcher $dispatcher,
        WebhookSigner $signer,
        TextRedactor $redactor,
        RestoreDrill $restoreDrill,
    ): void {
        // Before anything, including before the row is loaded. A restored copy holds
        // deliveries that were queued in the instance it was copied from, aimed at that
        // instance's consumers; the dispatcher's guard cannot see those because it never ran
        // for them. Throwing leaves the row `pending` and the job visibly failed, which is
        // what an operator who started a worker in a drill environment needs to see.
        $restoreDrill->assertNotDrilling('to deliver a webhook');

        $delivery = WebhookDelivery::query()->with(['event', 'endpoint'])->find($this->webhookDeliveryId);

        // A row that is already settled has been handled: a duplicate job
        // delivery must not produce a second POST.
        if ($delivery === null || $delivery->state !== DeliveryState::Pending) {
            return;
        }

        $endpoint = $delivery->endpoint;
        $event = $delivery->event;

        if (! $endpoint->isEnabled()) {
            $this->settle(
                $delivery,
                DeliveryState::Failed,
                error: 'The endpoint was disabled before this attempt ran; nothing was sent.',
            );

            return;
        }

        $config = $this->config();
        $timestamp = CarbonImmutable::now()->getTimestamp();
        $body = $event->canonical_body;
        $secrets = $endpoint->signingSecrets();

        try {
            $destination = $transport->validate($endpoint->url);
        } catch (DestinationRefusedException $refusal) {
            // Configuration, not weather: retrying cannot make the destination
            // acceptable, so the streak advances immediately.
            //
            // Through the redactor like every other stored string, even though this message
            // interpolates only a filtered host, a scheme, and a resolved address. Uniformity
            // is the point: it is the one path that skipped the control-character strip and
            // the length cap (docs/security/review-2026-09.md finding D-8).
            $this->settle($delivery, DeliveryState::Failed, error: $redactor->redact(
                $refusal->getMessage(),
                $secrets,
                (int) $config['response_excerpt_bytes'],
            ));
            $dispatcher->registerFailure($endpoint);

            return;
        }

        $headers = $signer->headers($secrets, $timestamp, $body) + [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => (string) $config['user_agent'],
            // Documented by the profile: the event type and the per-attempt id.
            'X-Firma-Event' => $event->event_name,
            'X-Firma-Delivery' => $delivery->public_id,
            // Our documented extensions: the logical event identity, stable
            // across every retry and replay, and which attempt this is.
            'X-Esign-Event-Id' => $event->public_id,
            'X-Esign-Attempt' => (string) $delivery->attempt,
        ];

        try {
            $response = $transport->send(
                $destination,
                $headers,
                $body,
                (int) $config['timeout'],
                (int) $config['connect_timeout'],
            );
        } catch (ConnectionException $exception) {
            // A timeout or a connection dropped mid-response. The receiver may
            // well have processed the event; we cannot know, which is exactly
            // why the event id is stable and deduplication is the receiver's job.
            $this->retryOrGiveUp(
                $delivery,
                $dispatcher,
                $timestamp,
                status: null,
                excerpt: null,
                error: 'The endpoint did not answer: '.$redactor->redact(
                    $exception->getMessage(),
                    $secrets,
                    (int) $config['response_excerpt_bytes'],
                ),
            );

            return;
        }

        $excerpt = $redactor->redact($response->body(), $secrets, (int) $config['response_excerpt_bytes']);

        if ($response->successful()) {
            $this->settle(
                $delivery,
                DeliveryState::Succeeded,
                signatureTimestamp: $timestamp,
                status: $response->status(),
                excerpt: $excerpt,
            );
            $dispatcher->registerSuccess($endpoint);

            return;
        }

        if (! $this->isRetryable($response)) {
            // A 4xx that is not 408 or 429 is the receiver saying the request
            // itself is wrong — a bad path, a rejected signature, a payload it
            // will not accept. Repeating it unchanged for two days cannot help,
            // so we stop and let the streak disable the endpoint. Replay is the
            // supported way back once the receiver is fixed.
            $this->settle(
                $delivery,
                DeliveryState::Failed,
                signatureTimestamp: $timestamp,
                status: $response->status(),
                excerpt: $excerpt,
                error: 'HTTP '.$response->status().' is not retryable; no further attempt will be made.',
            );
            $dispatcher->registerFailure($endpoint);

            return;
        }

        $this->retryOrGiveUp(
            $delivery,
            $dispatcher,
            $timestamp,
            status: $response->status(),
            excerpt: $excerpt,
            error: 'The endpoint answered HTTP '.$response->status().'.',
        );
    }

    /**
     * 2xx is success; 408 and 429 are the receiver asking for later; 5xx is the
     * receiver being broken right now. Everything else in 4xx is our request.
     */
    private function isRetryable(Response $response): bool
    {
        return $response->serverError()
            || in_array($response->status(), [408, 429], true);
    }

    private function retryOrGiveUp(
        WebhookDelivery $delivery,
        WebhookDispatcher $dispatcher,
        int $signatureTimestamp,
        ?int $status,
        ?string $excerpt,
        string $error,
    ): void {
        $dueAt = $dispatcher->nextAttemptAt($delivery->attempt);

        $this->settle(
            $delivery,
            $dueAt === null ? DeliveryState::Exhausted : DeliveryState::Failed,
            signatureTimestamp: $signatureTimestamp,
            status: $status,
            excerpt: $excerpt,
            error: $error,
            nextAttemptAt: $dueAt,
        );

        if ($dueAt === null) {
            $dispatcher->registerFailure($delivery->endpoint);

            return;
        }

        $dispatcher->queueRetry($delivery, $dueAt);
    }

    private function settle(
        WebhookDelivery $delivery,
        DeliveryState $state,
        ?int $signatureTimestamp = null,
        ?int $status = null,
        ?string $excerpt = null,
        ?string $error = null,
        ?CarbonImmutable $nextAttemptAt = null,
    ): void {
        $delivery->state = $state;
        $delivery->attempted_at = CarbonImmutable::now();
        $delivery->signature_timestamp = $signatureTimestamp;
        $delivery->response_status = $status;
        $delivery->response_excerpt = $excerpt === '' ? null : $excerpt;
        $delivery->error = $error;
        $delivery->next_attempt_at = $nextAttemptAt;
        $delivery->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        /** @var array<string, mixed> $config */
        $config = config('esign.delivery.webhooks', []);

        return $config + [
            'timeout' => 5,
            'connect_timeout' => 5,
            'user_agent' => 'BWH-eSign-Webhooks/1',
            'response_excerpt_bytes' => 1024,
        ];
    }
}
