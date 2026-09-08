{{-- To every recipient, including anyone who already signed. See App\Mail\CancelledMail. --}}
<x-mail::message>
# {{ $context->recipientName }},

**{{ $context->agreementTitle }}** has been cancelled by {{ $context->actorName }}. You are no longer being asked to sign it.

@if ($context->reason)
The reason given was:

> {{ $context->reason }}
@endif

Any link you were sent for this agreement no longer works. If you signed it already, that signature was not applied to a completed agreement.

If you think this is a mistake, reply to {{ $context->senderName ?: $brand }} rather than to this message.

Thanks,<br>
{{ $brand }}
</x-mail::message>
