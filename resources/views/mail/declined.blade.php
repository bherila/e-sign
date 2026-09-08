{{-- To the sender. No action link: see App\Mail\DeclinedMail. --}}
<x-mail::message>
# {{ $context->recipientName }},

{{ $context->actorName }} declined to sign **{{ $context->agreementTitle }}**.

@if ($context->reason)
They gave this reason:

> {{ $context->reason }}
@else
They did not give a reason.
@endif

Nothing further happens to this agreement on its own. Nobody else is being asked to sign it.

Thanks,<br>
{{ $brand }}
</x-mail::message>
