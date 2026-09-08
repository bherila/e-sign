<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mail;

use App\Domain\Delivery\Mail\Feedback\SesFeedbackProcessor;
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
 * Fails closed. The shipped SnsMessageVerifier refuses every message
 * (App\Domain\Delivery\Mail\Feedback\RejectingSnsMessageVerifier), so in this release the
 * endpoint accepts nothing and the code past the gate never runs over HTTP. It exists,
 * reviewed and tested, so that installing a real verifier is the only change required.
 *
 * 503 rather than 403 for an unverifiable message: the caller has done nothing wrong and
 * cannot fix it, and SNS treats 503 as retryable, so a deployment that turns verification
 * on does not lose the feedback that arrived in the meantime.
 */
class SesWebhookController extends Controller
{
    public function __invoke(
        SesWebhookRequest $request,
        SnsMessageVerifier $verifier,
        SesFeedbackProcessor $processor,
    ): JsonResponse {
        $envelope = $request->envelope();

        try {
            $verifier->verify($envelope);
        } catch (SnsVerificationException) {
            // Deliberately uninformative. An endpoint that mutates mail state should not
            // explain to an unauthenticated caller which part of their message failed.
            return response()->json([
                'status' => 'unverified',
                'message' => 'This deployment does not accept SES feedback.',
            ], 503);
        }

        return match ($request->messageType()) {
            'SubscriptionConfirmation' => $this->confirm($processor, $envelope),
            'Notification' => response()->json([
                'status' => $processor->processNotification($envelope) ? 'recorded' : 'ignored',
            ]),
            // An unsubscribe confirmation means the topic subscription is already gone.
            // There is nothing to record and nothing to undo from here.
            default => response()->json(['status' => 'ignored']),
        };
    }

    /**
     * Two failures with two different meanings, and they must not share a status code.
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
    private function confirm(SesFeedbackProcessor $processor, array $envelope): JsonResponse
    {
        try {
            $processor->confirmSubscription($envelope);
        } catch (RuntimeException) {
            return response()->json(['status' => 'rejected'], 422);
        } catch (ConnectionException|RequestException) {
            return response()->json(['status' => 'unconfirmed'], 503);
        }

        return response()->json(['status' => 'confirmed']);
    }
}
