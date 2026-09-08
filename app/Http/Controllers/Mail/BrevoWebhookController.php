<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mail;

use App\Domain\Delivery\Mail\Feedback\BrevoFeedbackProcessor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mail\BrevoWebhookRequest;
use Illuminate\Http\JsonResponse;

/**
 * Brevo transactional event feedback.
 *
 * Thin by design. Authentication is the Form Request's job, mapping and recording are the
 * Delivery module's, and this controller's only decision is that a batch always answers
 * 200: Brevo redelivers a batch on any non-2xx, so returning an error for one unrecognized
 * event would put the provider into a retry loop over an event this application has already
 * chosen to ignore.
 *
 * The counts in the response are for the operator reading Brevo's delivery log. They say
 * nothing about which messages or whose addresses were involved.
 */
class BrevoWebhookController extends Controller
{
    public function __invoke(BrevoWebhookRequest $request, BrevoFeedbackProcessor $processor): JsonResponse
    {
        $result = $processor->process($request->events());

        return response()->json([
            'status' => 'ok',
            'recorded' => $result['recorded'],
            'ignored' => $result['ignored'],
        ]);
    }
}
