<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Integration\Native\EnvelopeService;
use App\Http\Requests\Api\V1\CancelEnvelopeRequest;
use App\Http\Requests\Api\V1\EnvelopeRequest;
use App\Http\Requests\Api\V1\StoreEnvelopeRequest;
use App\Http\Requests\Api\V1\UpdateEnvelopeRequest;
use App\Http\Resources\Api\V1\EnvelopeResource;
use App\Http\Resources\Api\V1\RecipientResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The envelope lifecycle, as five calls.
 *
 * Every one of them delegates to {@see EnvelopeService}, which delegates in turn to the
 * signing state machine. There is no rule in this class about who may act, when, or what a
 * transition means — AGENTS.md: "Never put signing rules in a compatibility controller", and
 * a native controller is no different. What is here is HTTP: which status a result gets, and
 * which resource renders it.
 *
 * The statuses are worth stating once, because they are the contract:
 *
 * | Situation | Status |
 * |---|---|
 * | created | `201` |
 * | read, patched, sent, cancelled | `200` |
 * | id belongs to another workspace, or to nothing | `404` |
 * | the transition is illegal from this state (send twice, cancel a completed envelope) | `409` |
 * | the request or a value in it is wrong | `422` |
 *
 * A `409` is never retried into success by the same request; a `422` usually is, after the
 * caller fixes what it named.
 */
class EnvelopeController extends ApiController
{
    public function __construct(private readonly EnvelopeService $envelopes) {}

    public function store(StoreEnvelopeRequest $request): JsonResponse
    {
        $envelope = $this->envelopes->create(
            $request->workspace(),
            $request->envelopeInput(),
            $request->credential(),
        );

        return $this->item(
            EnvelopeResource::make($envelope)->resolve($request),
            Response::HTTP_CREATED,
        );
    }

    public function show(EnvelopeRequest $request): JsonResponse
    {
        return $this->item(EnvelopeResource::make($request->envelope())->resolve($request));
    }

    /**
     * Correct a draft. Anything else is `409 illegal_transition`, decided by the service.
     */
    public function update(UpdateEnvelopeRequest $request): JsonResponse
    {
        $envelope = $this->envelopes->updateDraft(
            $request->envelope(),
            $request->prefills(),
            $request->recipientCorrections(),
            $request->credential(),
        );

        return $this->item(EnvelopeResource::make($envelope)->resolve($request));
    }

    /**
     * Issue the invitations.
     *
     * The send gate reports every problem at once rather than the first one, so a caller
     * fixing an envelope sees the whole list: `422 send_preconditions_failed` carries them
     * under `details.problems`.
     */
    public function send(EnvelopeRequest $request): JsonResponse
    {
        $envelope = $this->envelopes->send($request->envelope());

        return $this->item(EnvelopeResource::make($envelope)->resolve($request));
    }

    /**
     * Withdraw the envelope.
     *
     * Legal from `draft`, `sent`, and `in_progress`. Not from `finalizing`: everyone has
     * signed by then and an artifact is being produced from their acceptances. Not from a
     * terminal state either — cancelling a completed envelope is `409`, and an envelope that
     * is already cancelled stays cancelled with its original reason rather than acquiring a
     * new one.
     */
    public function cancel(CancelEnvelopeRequest $request): JsonResponse
    {
        $envelope = $this->envelopes->cancel($request->envelope(), $request->reason());

        return $this->item(EnvelopeResource::make($envelope)->resolve($request));
    }

    /**
     * The parties, in signing order.
     *
     * Returned whole: the count is bounded by the envelope's own field schema, so paging it
     * would be theatre. It still carries `meta.next_cursor: null` so a client's paging loop
     * does not need a special case.
     */
    public function recipients(EnvelopeRequest $request): JsonResponse
    {
        return $this->collection(
            $request,
            $this->envelopes->recipients($request->envelope()),
            RecipientResource::class,
        );
    }
}
