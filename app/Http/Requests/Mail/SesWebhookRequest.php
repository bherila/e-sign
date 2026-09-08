<?php

declare(strict_types=1);

namespace App\Http\Requests\Mail;

use App\Domain\Delivery\Mail\Feedback\SesFeedbackRefusals;
use App\Domain\Delivery\Mail\Feedback\SnsTopicAllowlist;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /webhooks/mail/ses.
 *
 * SNS posts its JSON with `Content-Type: text/plain; charset=UTF-8`, so Laravel does not
 * treat the body as JSON and the input source is empty. The body is decoded from the raw
 * content here rather than in the controller, which is also what makes the rules below
 * apply to anything at all.
 *
 * authorize() checks only the topic ARN, against the allowlist in
 * `esign.mail.ses.topic_arns`. It is emphatically *not* the security check — a topic ARN is
 * not a secret and appears in AWS console URLs and CloudTrail. The real check is the SNS
 * signature, which SnsMessageVerifier performs in the controller.
 *
 * It is here anyway, ahead of the signature, for two reasons. It is the cheap one: rejecting
 * somebody else's traffic before a certificate fetch and a public-key operation keeps this
 * endpoint from being a work amplifier. And the answers differ — a foreign topic is a
 * permanent 403 that SNS should stop retrying, while an unverifiable message is a retryable
 * 503. AwsSnsMessageVerifier checks the allowlist a second time, so neither layer is
 * load-bearing on its own.
 *
 * An empty allowlist disables the endpoint.
 */
class SesWebhookRequest extends FormRequest
{
    public const MESSAGE_TYPES = [
        'SubscriptionConfirmation',
        'UnsubscribeConfirmation',
        'Notification',
    ];

    public function authorize(): bool
    {
        return $this->topics()->allows($this->input('TopicArn'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isConfirmation = fn (): bool => in_array(
            $this->input('Type'),
            ['SubscriptionConfirmation', 'UnsubscribeConfirmation'],
            true,
        );

        return [
            'Type' => ['required', 'string', Rule::in(self::MESSAGE_TYPES)],
            'TopicArn' => ['required', 'string', 'max:2048'],
            'MessageId' => ['required', 'string', 'max:191'],
            /*
             * Required, not nullable. `Message` and `Timestamp` are in the string-to-sign for
             * every SNS message type and SNS always sends both, so an envelope missing either
             * cannot verify. Refusing it here makes it a 422 — a malformed body — rather than
             * a 503 that reads like this deployment declining a well-formed one.
             */
            'Message' => ['required', 'string'],
            'Timestamp' => ['required', 'string', 'max:64'],
            'Signature' => ['required', 'string'],
            'SignatureVersion' => ['required', 'string', 'max:8'],
            'SigningCertURL' => ['required', 'string', 'max:2048'],
            /*
             * Both are in the string-to-sign for a subscription or unsubscribe confirmation,
             * and `Token` is what confirms one, so on those two types they are required for
             * the same reason `Message` is. `Subject` is optional everywhere and must stay
             * *absent* rather than null when SNS omits it, because an empty `Subject` in the
             * signed string is a signature that never verifies.
             */
            'SubscribeURL' => [Rule::requiredIf($isConfirmation), 'nullable', 'string', 'max:2048'],
            'Token' => [Rule::requiredIf($isConfirmation), 'nullable', 'string', 'max:2048'],
            'Subject' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messageType(): string
    {
        return (string) $this->validated()['Type'];
    }

    /**
     * The whole validated SNS envelope, which is what a signature covers.
     *
     * @return array<string, mixed>
     */
    public function envelope(): array
    {
        /** @var array<string, mixed> $envelope */
        $envelope = $this->validated();

        return $envelope;
    }

    /**
     * A refused topic is recorded, not silently 403'd.
     *
     * An operator who has just pointed SNS at this endpoint and mistyped the ARN sees
     * nothing at all otherwise: SNS reports a 403 on its own side, this side reports
     * nothing, and the two are never looked at together. `topic_not_allowlisted` with the
     * ARN that was actually offered answers it in one line. Collapsed per window by
     * SesFeedbackRefusals, because this is a public POST surface.
     */
    protected function failedAuthorization(): void
    {
        /** @var SesFeedbackRefusals $refusals */
        $refusals = $this->container->make(SesFeedbackRefusals::class);

        $refusals->record(
            $this->topics()->isConfigured() ? 'topic_not_allowlisted' : 'no_topic_configured',
            $this->all(),
        );

        parent::failedAuthorization();
    }

    protected function prepareForValidation(): void
    {
        // The input source, not all(): a query parameter must not be mistaken for a body
        // and leave the envelope undecoded.
        if ($this->getInputSource()->count() > 0) {
            return;
        }

        $decoded = json_decode((string) $this->getContent(), true);

        if (is_array($decoded) && ! array_is_list($decoded)) {
            $this->merge($decoded);
        }
    }

    private function topics(): SnsTopicAllowlist
    {
        return SnsTopicAllowlist::fromConfig(config('esign.mail.ses.topic_arns', []));
    }
}
