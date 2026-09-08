{{--
    Invitation. Markdown mail: Laravel renders this file for the HTML part and renders it
    again through the text components for the plain-text alternative, so there is one copy
    of the wording rather than two that drift.

    No image, no remote stylesheet, no tracking pixel. The only URL in the message is
    $context->actionUrl, and it appears exactly once.
--}}
<x-mail::message>
# {{ $context->recipientName }},

{{ $context->senderName }} has asked you to review and sign **{{ $context->agreementTitle }}**.

<x-mail::button :url="$context->actionUrl">
Review and sign
</x-mail::button>

@if ($context->expiresAt)
This link stops working on {{ $context->expiresAt->timezone(config('app.timezone'))->format('j M Y, H:i T') }}. If it has expired by the time you get to it, ask {{ $context->senderName }} to send a new one.
@else
If the link does not work, ask {{ $context->senderName }} to send a new one.
@endif

Opening the link shows you the document. Nothing is signed until you say so on that page.

If you were not expecting this, you can ignore it — no signature is applied by doing nothing.

Thanks,<br>
{{ $brand }}
</x-mail::message>
