<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mail;

use App\Domain\Delivery\Mail\Feedback\SesFeedbackProcessor;
use App\Domain\Delivery\Mail\Feedback\SesFeedbackRefusals;
use App\Domain\Delivery\Mail\Feedback\SnsMessageVerifier;
use App\Domain\Delivery\Mail\Feedback\SnsVerificationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mail\SesWebhookRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * SES feedback, delivered through SNS.
 *
 * Nothing past the verifier runs until the message is proven to have come from AWS *and*
 * from a topic this deployment was told to accept. The bound verifier is
 * AwsSnsMessageVerifier when `esign.mail.ses.topic_arns` names a topic and
 * RejectingSnsMessageVerifier when it does not, so an unconfigured deployment still accepts
 * nothing.
 *
 * 503 rather than 403 for an unverifiable message: the caller has done nothing wrong and
 * cannot fix it, and SNS treats 503 as retryable, so a deployment that fixes its
 * configuration does not lose the feedback that arrived in the meantime. The body says
 * nothing about which check failed — an endpoint that mutates mail state should not explain
 * to an unauthenticated caller how to satisfy it — but the refusal is recorded on this side,
 * with its reason, so an operator is not left guessing either.
 */
class SesWebhookController extends Controller
{
    public function __invoke(
        SesWebhookRequest $request,
        SnsMessageVerifier $verifier,
        SesFeedbackProcessor $processor,
        SesFeedbackRefusals $refusals,
    ): JsonResponse {
        $envelope = $request->envelope();

        try {
            $verifier->verify($envelope);
        } catch (SnsVerificationException $exception) {
            $refusals->record($exception->reason, $envelope);

            // Deliberately uninformative. An endpoint that mutates mail state should not
            // explain to an unauthenticated caller which part of their message failed.
            return response()->json([
                'status' => 'unverified',
                'message' => 'This deployment does not accept SES feedback.',
            ], 503);
        }

        return match ($request->messageType()) {
            'SubscriptionConfirmation' => $this->confirm($processor, $refusals, $envelope),
            'Notification' => response()->json([
                'status' => $processor->processNotification($envelope) ? 'recorded' : 'ignored',
            ]),
            // An unsubscribe confirmation means the topic subscription is already gone.
            // There is nothing to record and nothing to undo from here.
            default => response()->json(['status' => 'ignored']),
        };
    }

    /**
     * Confirm a subscription, if this deployment confirms subscriptions at all.
     *
     * The switch is off by default and the answer when it is off is **200**, not an error.
     * The message was genuine and correctly addressed; the application has simply decided
     * that starting to receive a topic's traffic is a person's decision, taken once in the
     * SNS console. A non-2xx would make SNS retry a confirmation this deployment has
     * declined on purpose, which is noise rather than safety. The declined confirmation is
     * recorded so an operator can see the subscription is waiting for them.
     *
     * When it is on, two failures with two different meanings, and they must not share a
     * status code.
     *
     * A confirmation URL that is not an AWS SNS endpoint, or that resolves somewhere it
     * should not, is the shape of a server-side request forgery attempt. It will never
     * succeed, so 422 and no detail about what was rejected.
     *
     * AWS being slow or answering 5xx is transient and nobody's fault. Those arrive as the
     * HTTP client's own exceptions, which are not RuntimeExceptions, so they are caught
     * separately and answered 503 — SNS retries a 503, and losing a subscription
     * confirmation to a momentary blip would leave the topic unsubscribed with nothing to
     * say why.
     *
     * @param  array<string, mixed>  $envelope
     */
    private function confirm(
        SesFeedbackProcessor $processor,
        SesFeedbackRefusals $refusals,
        array $envelope,
    ): JsonResponse {
        if (! $processor->autoConfirmEnabled()) {
            $refusals->record('auto_confirm_disabled', $envelope);

            return response()->json(['status' => 'not_confirmed']);
        }

        try {
            $processor->confirmSubscription($envelope);
        } catch (RuntimeException) {
            $refusals->record('subscribe_url_refused', $envelope);

            return response()->json(['status' => 'rejected'], 422);
        } catch (ConnectionException|RequestException) {
            return response()->json(['status' => 'unconfirmed'], 503);
        }

        return response()->json(['status' => 'confirmed']);
    }
}
