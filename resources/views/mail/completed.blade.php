{{--
    To every party. The executed PDF is not attached; $context->actionUrl is a download URL
    that streams through the application. See App\Mail\CompletedMail.
--}}
<x-mail::message>
# {{ $context->recipientName }},

**{{ $context->agreementTitle }}** is complete. Everyone who was asked to sign has done so.

@if ($context->actionUrl)
<x-mail::button :url="$context->actionUrl">
Download the executed agreement
</x-mail::button>

The document is not attached to this message. It is served from {{ $brand }}, where the request is authorized each time.
@else
The executed document is available from {{ $brand }}. Sign in to download it.
@endif

Keep this message for your records.

Thanks,<br>
{{ $brand }}
</x-mail::message>
