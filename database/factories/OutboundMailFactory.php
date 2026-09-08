<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Delivery\Mail\MailKind;
use App\Domain\Delivery\Mail\MailState;
use App\Domain\Delivery\Mail\Models\OutboundMail;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Synthetic outbox rows. Addresses use `.test`, which is reserved by RFC 6761 and can never
 * resolve, so a fixture that escapes into a real mailer has nowhere to go.
 *
 * @extends Factory<OutboundMail>
 */
class OutboundMailFactory extends Factory
{
    protected $model = OutboundMail::class;

    public function definition(): array
    {
        $recipient = fake()->name();

        return [
            'public_id' => (string) Str::ulid(),
            'workspace_id' => null,
            'kind' => MailKind::Invitation,
            'to_email' => Str::slug($recipient).'@recipient.test',
            'to_name' => $recipient,
            'subject' => 'Example Sender asked you to sign "Mutual Nondisclosure Agreement"',
            'context' => [
                'recipient_name' => $recipient,
                'sender_name' => 'Example Sender',
                'agreement_title' => 'Mutual Nondisclosure Agreement',
                'action_url' => 'https://esign.example.test/sign/'.Str::ulid(),
            ],
            'state' => MailState::Queued,
            'state_changed_at' => Carbon::now(),
            'attempts' => 0,
        ];
    }

    public function sentToProvider(?string $messageId = null): self
    {
        return $this->state(fn (): array => [
            'state' => MailState::SentToProvider,
            'state_changed_at' => Carbon::now(),
            'attempts' => 1,
            'mailer' => 'smtp',
            'message_id' => $messageId ?? strtolower(Str::ulid()->toString()).'@mail.example.test',
        ]);
    }

    public function inState(MailState $state): self
    {
        return $this->state(fn (): array => [
            'state' => $state,
            'state_changed_at' => Carbon::now(),
        ]);
    }

    public function queuedAt(Carbon $createdAt): self
    {
        return $this->state(fn (): array => [
            'state' => MailState::Queued,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            'state_changed_at' => $createdAt,
        ]);
    }
}
