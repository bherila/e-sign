<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Mail\Feedback;

use App\Domain\Delivery\Mail\MailEventSource;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Turns a batch of Brevo webhook events into recorded feedback.
 *
 * Brevo posts either one event object or several, and it retries the whole batch on a
 * non-2xx response. So one bad event must not cost the good ones: each is recorded
 * independently, and the endpoint reports how many it took rather than failing the batch.
 */
final class BrevoFeedbackProcessor
{
    public function __construct(
        private readonly BrevoEventMapper $mapper,
        private readonly MailFeedbackRecorder $recorder,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $events
     * @return array{recorded: int, ignored: int}
     */
    public function process(array $events): array
    {
        $recorded = 0;
        $ignored = 0;

        foreach ($events as $event) {
            $name = is_string($event['event'] ?? null) ? $event['event'] : '';

            if ($name === '' || $this->mapper->isIgnored($name)) {
                $ignored++;

                continue;
            }

            try {
                $this->recorder->record(
                    source: MailEventSource::Brevo,
                    event: $name,
                    messageId: is_string($event['message_id'] ?? null) ? $event['message_id'] : null,
                    state: $this->mapper->map($name),
                    payload: $event,
                    occurredAt: $this->occurredAt($event),
                );

                $recorded++;
            } catch (Throwable) {
                // Swallowed on purpose. A single malformed event in a batch must not make
                // Brevo redeliver the batch forever, and it must not make the endpoint look
                // broken to the provider's health checks. The event is lost; the state it
                // would have set is recoverable from the next event about the same message.
                $ignored++;
            }
        }

        return ['recorded' => $recorded, 'ignored' => $ignored];
    }

    /**
     * Brevo timestamps an event three different ways depending on the event type: `ts_event`
     * and `ts` are Unix seconds, `date` is a formatted local string. Preferring the numeric
     * ones avoids parsing a string whose timezone is a provider setting.
     *
     * @param  array<string, mixed>  $event
     */
    private function occurredAt(array $event): ?Carbon
    {
        foreach (['ts_event', 'ts'] as $key) {
            $value = $event[$key] ?? null;

            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                return Carbon::createFromTimestampUTC((int) $value);
            }
        }

        $date = $event['date'] ?? null;

        if (is_string($date) && trim($date) !== '') {
            try {
                return Carbon::parse($date);
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }
}
