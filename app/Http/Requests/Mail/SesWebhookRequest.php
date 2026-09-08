<?php

declare(strict_types=1);

namespace App\Http\Requests\Mail;

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
 * authorize() checks only the topic ARN: this endpoint serves exactly one SNS topic, named
 * in configuration, and a message from any other topic is somebody else's traffic. It is
 * emphatically *not* the security check — a topic ARN is not a secret and appears in AWS
 * console URLs and CloudTrail. The real check is the SNS signature, which
 * SnsMessageVerifier performs in the controller and which currently rejects everything by
 * design. See App\Domain\Delivery\Mail\Feedback\RejectingSnsMessageVerifier.
 *
 * An unset topic ARN disables the endpoint.
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
        $configured = trim((string) config('esign.mail.ses_topic_arn'));

        if ($configured === '') {
            return false;
        }

        $provided = $this->input('TopicArn');

        if (! is_string($provided) || trim($provided) === '') {
            return false;
        }

        return hash_equals($configured, trim($provided));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'Type' => ['required', 'string', Rule::in(self::MESSAGE_TYPES)],
            'TopicArn' => ['required', 'string', 'max:2048'],
            'MessageId' => ['required', 'string', 'max:191'],
            // The SES notification, as a JSON string inside the envelope.
            'Message' => ['nullable', 'string'],
            'Timestamp' => ['nullable', 'string', 'max:64'],
            // Required so an unsigned body is a 422 rather than reaching the verifier and
            // being refused for a reason that reads like a configuration problem.
            'Signature' => ['required', 'string'],
            'SignatureVersion' => ['required', 'string', 'max:8'],
            'SigningCertURL' => ['required', 'string', 'max:2048'],
            'SubscribeURL' => ['nullable', 'string', 'max:2048'],
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
}
