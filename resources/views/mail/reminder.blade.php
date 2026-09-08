{{-- Reminder. Same single link as the invitation; see App\Mail\ReminderMail. --}}
<x-mail::message>
# {{ $context->recipientName }},

**{{ $context->agreementTitle }}** is still waiting for your signature. {{ $context->senderName }} sent it to you earlier.

<x-mail::button :url="$context->actionUrl">
Review and sign
</x-mail::button>

@if ($context->expiresAt)
This link stops working on {{ $context->expiresAt->timezone(config('app.timezone'))->format('j M Y, H:i T') }}.
@endif

If you have decided not to sign, you can say so on the same page. Declining is a normal outcome and it tells {{ $context->senderName }} where things stand.

Thanks,<br>
{{ $brand }}
</x-mail::message>
