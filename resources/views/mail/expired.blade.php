{{-- To every invited recipient and to the sender. No action link: see App\Mail\ExpiredMail. --}}
<x-mail::message>
# {{ $context->recipientName }},

**{{ $context->agreementTitle }}** expired before everyone had signed it. Nobody cancelled it; the signing window that was set when it was sent has now passed.

@if ($context->expiresAt)
The window closed on {{ $context->expiresAt->timezone(config('app.timezone'))->format('j M Y, H:i T') }}.
@endif

Any link you were sent for this agreement no longer works. If you signed it already, that signature was not applied to a completed agreement.

If it still needs signing, {{ $context->senderName ?: $brand }} has to send it again as a new agreement.

Thanks,<br>
{{ $brand }}
</x-mail::message>
