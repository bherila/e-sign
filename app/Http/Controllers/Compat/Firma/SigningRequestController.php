<?php

declare(strict_types=1);

namespace App\Http\Controllers\Compat\Firma;

use App\Domain\Integration\Firma\FirmaErrorCode;
use App\Domain\Integration\Firma\FirmaException;
use App\Domain\Integration\Firma\SigningRequestCreation;
use App\Domain\Integration\Firma\SigningRequestDownloads;
use App\Domain\Integration\Firma\SigningRequestFields;
use App\Domain\Integration\Firma\SigningRequestLocator;
use App\Domain\Integration\Firma\SigningRequestSettings;
use App\Domain\Integration\Firma\SigningRequestUsers;
use App\Domain\Integration\Firma\UnsupportedOptions;
use App\Domain\Integration\Native\EnvelopeService;
use App\Domain\Signing\Exceptions\SendPreconditionsFailed;
use App\Domain\Signing\Models\Envelope;
use App\Domain\Signing\Models\EnvelopeRecipient;
use App\Http\Controllers\Controller;
use App\Http\Requests\Compat\Firma\CancelSigningRequestRequest;
use App\Http\Requests\Compat\Firma\CreateAndSendRequest;
use App\Http\Requests\Compat\Firma\FirmaRequest;
use App\Http\Requests\Compat\Firma\PatchSigningRequestRequest;
use App\Http\Requests\Compat\Firma\StoreSigningRequestRequest;
use App\Http\Resources\Compat\Firma\CancelSigningRequestResource;
use App\Http\Resources\Compat\Firma\CreateAndSendResource;
use App\Http\Resources\Compat\Firma\PatchedFieldResource;
use App\Http\Resources\Compat\Firma\PatchedRecipientResource;
use App\Http\Resources\Compat\Firma\SendSigningRequestResource;
use App\Http\Resources\Compat\Firma\SigningRequestCreateResource;
use App\Http\Resources\Compat\Firma\SigningRequestDetailResource;
use Illuminate\Http\JsonResponse;

/**
 * The signing-request lifecycle, in somebody else's vocabulary.
 *
 * Every method here is a translation and nothing more. The transitions are
 * App\Domain\Integration\Native\EnvelopeService's — the same object `/api/v1` calls, which
 * in turn is the same state machine the signing UI drives — so there is exactly one place
 * that decides whether a request may be sent, patched, or withdrawn (AGENTS.md, "One state
 * machine"). A rule that lived in this class would be a second answer to a question that
 * must only have one.
 *
 * What the controller does own is which serializer each route uses, and they are deliberately
 * different: create returns a string `status`, polling returns an object of booleans, and
 * `/download` returns a third representation again. `docs/HANDOFF.md` section 10 requires
 * exactly that — "a create response and a polling response need not use the same status
 * type" — so there is no shared base resource to inherit here and no `$this->item()` helper
 * flattening them into one shape.
 */
class SigningRequestController extends Controller
{
    public function __construct(
        private readonly SigningRequestCreation $creation,
        private readonly SigningRequestLocator $locator,
        private readonly EnvelopeService $envelopes,
        private readonly SigningRequestFields $fields,
        private readonly SigningRequestUsers $users,
        private readonly SigningRequestDownloads $downloads,
        private readonly SigningRequestSettings $settings,
    ) {}

    /** `POST /signing-requests` — a draft, from a template or from an inline document. */
    public function store(StoreSigningRequestRequest $request): JsonResponse
    {
        $envelope = $this->creation->create($request->workspace(), $request->credential(), $request->body());

        return response()->json(
            (new SigningRequestCreateResource($envelope, $this->fields, $this->downloads, $this->settings))->resolve($request),
            201,
        );
    }

    /**
     * `POST /signing-requests/create-and-send` — create and release in one call.
     *
     * The two phases are kept distinguishable, as upstream's `phase` enum requires: a body
     * that cannot be turned into a request fails in `create_validation` with nothing
     * persisted, and one that is built and then refused by the send gate fails in
     * `send_validation` naming the parties who are not ready. Neither leaves a half-built
     * draft: creation is one transaction, and a refused send is reported with the draft
     * deleted from nobody's view because it was never announced.
     *
     * The send resolves the document's anchors a second time — a receipt never licenses skipping
     * resolution — but it does not *read* the document a second time. `Documents\RevisionBytes`
     * is bound `scoped` and carries the bytes it proved during creation through to here, so
     * there is no window in which the object can become unavailable between the two phases and
     * turn one call into an error, an orphan draft, and a duplicate on the retry.
     */
    public function createAndSend(CreateAndSendRequest $request): JsonResponse
    {
        $envelope = $this->creation->create($request->workspace(), $request->credential(), $request->body());
        $envelope = $this->sendOrExplain($envelope);
        $firstSigner = $this->firstSigner($envelope);

        return response()->json(
            (new CreateAndSendResource(
                $envelope,
                $this->fields,
                $firstSigner,
                $firstSigner === null ? null : $this->signingLink($firstSigner),
            ))->resolve($request),
            201,
        );
    }

    /** `GET /signing-requests/{id}` — the polling endpoint. */
    public function show(FirmaRequest $request): JsonResponse
    {
        return response()->json(
            (new SigningRequestDetailResource($request->signingRequest(), $this->downloads, $this->settings))->resolve($request),
        );
    }

    /**
     * `PATCH /signing-requests/{id}` — one field, or one party's contact details.
     *
     * Both go through `EnvelopeService::updateDraft()`, which refuses a request that is no
     * longer a draft with the same `409` any other illegal move gets. That guard is upstream's
     * too ("cannot update after the signing request has been sent, completed, or cancelled")
     * and it is enforced by the state machine rather than checked here.
     */
    public function update(PatchSigningRequestRequest $request): JsonResponse
    {
        $envelope = $request->signingRequest();
        [$kind, $payload] = $request->target();

        if ($kind === 'field') {
            return response()->json($this->patchField($request, $envelope, $payload));
        }

        return response()->json($this->patchRecipient($request, $envelope, $payload));
    }

    /** `POST /signing-requests/{id}/send`. */
    public function send(FirmaRequest $request): JsonResponse
    {
        $envelope = $this->envelopes->send($request->signingRequest());

        return response()->json((new SendSigningRequestResource($envelope))->resolve($request));
    }

    /**
     * `POST /signing-requests/{id}/cancel`.
     *
     * Upstream answers `409` for a draft — "can only cancel requests that have been sent" —
     * and this facade does **not**. Withdrawing a draft is a legal transition here, and
     * refusing to perform something this service can do, in order to reproduce a limitation
     * of somebody else's implementation, would leave the caller holding a draft they asked us
     * to withdraw. That is recorded in the capability matrix as an intentional difference; a
     * request that is already cancelled or finished is still a `409`, because that one is a
     * genuine conflict rather than a limitation.
     */
    public function cancel(CancelSigningRequestRequest $request): JsonResponse
    {
        UnsupportedOptions::guardCancelNotification($request->notifySigners());

        $envelope = $this->envelopes->cancel($request->signingRequest(), $request->reason());

        return response()->json((new CancelSigningRequestResource($envelope))->resolve($request));
    }

    /* ---------------------------------------------------------------------- patch */

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws FirmaException
     */
    private function patchField(PatchSigningRequestRequest $request, Envelope $envelope, array $payload): array
    {
        $field = $this->locator->fieldOf(
            $envelope,
            self::string($payload, 'id') ?? self::string($payload, 'template_field_id'),
            self::string($payload, 'variable_name'),
        );

        $this->guardEditability($payload, $field->readOnly);

        $value = array_key_exists('value', $payload)
            ? $payload['value']
            : ($payload['final_value'] ?? $payload['read_only_value'] ?? null);

        $updated = $this->envelopes->updateDraft(
            $envelope,
            [$field->id => $value],
            [],
            $request->credential(),
        );

        return (new PatchedFieldResource($this->fields->one($updated, $field->id)))->resolve($request);
    }

    /**
     * `prefilled_editable` describes the copied field schema, which does not change.
     *
     * A value that agrees with the field is honoured as the no-op it is. One that disagrees is
     * a `501` naming the option, because the alternative is accepting an instruction and
     * doing nothing about it — which is the successful no-op AGENTS.md forbids, and here it
     * would leave a caller believing a read-only clause had been opened for editing.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws FirmaException
     */
    private function guardEditability(array $payload, bool $readOnly): void
    {
        foreach (['prefilled_editable' => ! $readOnly, 'read_only' => $readOnly] as $key => $current) {
            $requested = $payload[$key] ?? null;

            if (is_bool($requested) && $requested !== $current) {
                throw FirmaException::unsupported(
                    'field.'.$key,
                    'Whether a field is editable is part of the field schema this signing request was built '
                    .'from, and that schema is immutable once copied — it is what lets an executed agreement '
                    .'be proved against what the parties were shown. Change the template and build a new '
                    .'signing request instead.',
                    ['current' => $current, 'requested' => $requested],
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws FirmaException
     */
    private function patchRecipient(PatchSigningRequestRequest $request, Envelope $envelope, array $payload): array
    {
        $recipient = $this->recipient($envelope, self::string($payload, 'id'));

        $name = trim(implode(' ', array_filter([
            trim((string) ($payload['first_name'] ?? '')),
            trim((string) ($payload['last_name'] ?? '')),
        ]))) ?: self::string($payload, 'name');

        $updated = $this->envelopes->updateDraft(
            $envelope,
            [],
            [[
                'id' => $recipient->schema_recipient_id,
                'name' => $name,
                'email' => self::string($payload, 'email'),
            ]],
            $request->credential(),
        );

        foreach ($this->users->results($updated) as $row) {
            if ($row['id'] === $recipient->public_id) {
                return (new PatchedRecipientResource($row))->resolve($request);
            }
        }

        throw FirmaException::notFound('recipient');
    }

    /**
     * @throws FirmaException
     */
    private function recipient(Envelope $envelope, ?string $id): EnvelopeRecipient
    {
        if ($id === null) {
            throw FirmaException::of(
                FirmaErrorCode::InvalidRequest,
                'A recipient patch must name the recipient by `id`. Matching on an email address would bind '
                .'the correction to an address that may itself be the thing being corrected.',
            );
        }

        $recipient = $envelope->recipients()->where('public_id', $id)->first();

        if (! $recipient instanceof EnvelopeRecipient) {
            throw FirmaException::notFound('recipient');
        }

        return $recipient;
    }

    /* ----------------------------------------------------------------------- send */

    /**
     * Send, and translate a refusal into the two-phase shape.
     *
     * @throws FirmaException
     */
    private function sendOrExplain(Envelope $envelope): Envelope
    {
        try {
            return $this->envelopes->send($envelope);
        } catch (SendPreconditionsFailed $failure) {
            throw FirmaException::createAndSendValidation(
                'send_validation',
                $failure->getMessage(),
                $this->missingByRecipient($envelope),
            );
        }
    }

    /**
     * Upstream's `validation_errors[]`: `{recipient_index (1-based), recipient_email,
     * missing_fields[]}`.
     *
     * Reproduced verbatim because it is how the consumer shows a human which prefill it
     * forgot. The rows come from the same readiness projection `/users` reports, so a caller
     * that polls `/users` after a refused send sees the same answer.
     *
     * @return list<array{recipient_index: int, recipient_email: string|null, missing_fields: list<string>}>
     */
    private function missingByRecipient(Envelope $envelope): array
    {
        $rows = [];

        foreach ($this->users->results($envelope) as $index => $user) {
            $missing = $user['missing_fields'];

            foreach ($user['required_read_only_fields'] as $field) {
                if ($field['has_value'] === false) {
                    $missing[] = $field['variable_name'] ?? 'unnamed field';
                }
            }

            if ($missing === []) {
                continue;
            }

            $rows[] = [
                'recipient_index' => $index + 1,
                'recipient_email' => $user['email'],
                'missing_fields' => array_values($missing),
            ];
        }

        return $rows;
    }

    private function firstSigner(Envelope $envelope): ?EnvelopeRecipient
    {
        if (! $envelope->relationLoaded('recipients')) {
            $envelope->setRelation('recipients', $envelope->recipients()->get());
        }

        foreach ($envelope->getRelation('recipients') as $recipient) {
            /** @var EnvelopeRecipient $recipient */
            if ($recipient->invited_at !== null) {
                return $recipient;
            }
        }

        // Nobody has been written to yet — the mail is scheduled on the queue — so the first
        // party of the first stage is the one who will be.
        return $envelope->getRelation('recipients')->first();
    }

    /**
     * The signer's link: the stable resolver, **not** a fresh invitation credential.
     *
     * This deliberately does *not* call `SigningUrlMinter`, even though that is what the
     * invitation email uses. A recipient has at most one live invitation and issuing another
     * revokes the previous one (`InvitationIssuer`), so minting one here would either kill
     * the link the invitation email is about to send or be killed by it, depending on when
     * the queue got to the job. Either way the URL in this response would be a link that
     * goes nowhere, which is the exact failure the placeholder minter was written to
     * prevent. Handing out a second live credential would also widen the set of people who
     * can execute the agreement, which is the reason only one is ever live.
     *
     * `signing.legacy.show` is stable, revokes nothing, and authorizes nothing on its own:
     * a bare recipient identifier reaches a form, and stating the address the invitation
     * went to plus reading a code delivered there is what turns it into a session
     * (`docs/HANDOFF.md` §8 — "A public recipient UUID alone must not authorize signing …
     * a legacy `/signing/{recipientId}` resolver can require mailbox verification").
     *
     * It is also the same *path shape* the consumer already hardcodes, so the change it has
     * to make is the host and nothing else.
     */
    private function signingLink(EnvelopeRecipient $recipient): string
    {
        return route('signing.legacy.show', ['recipient' => $recipient->public_id]);
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private static function string(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
