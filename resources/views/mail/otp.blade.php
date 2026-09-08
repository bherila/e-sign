{{--
    The one-time code for a guest signing session.

    No button, no link, no image, no tracking pixel. A code and a one-click URL in the same
    message would let one forwarded mail do both halves of the check this code exists to
    separate, so this template deliberately has nowhere to click — which is also why
    MailableRenderingTest can assert it links nowhere at all.

    The code is rendered as plain text rather than in a styled panel: mail clients with
    remote content and CSS disabled are exactly the clients a security-conscious recipient
    is using, and a code they cannot see is a code they cannot enter.
--}}
<x-mail::message>
# {{ $context->recipientName }},

Enter this code on the page you already have open to start signing
**{{ $context->agreementTitle }}**:

# {{ $context->otpCode }}

@if ($context->expiresAt)
The code stops working at {{ $context->expiresAt->timezone(config('app.timezone'))->format('j M Y, H:i T') }}. Ask for another on the same page if it has expired.
@else
Ask for another on the same page if it has expired.
@endif

Nobody from {{ $brand }} will ever ask you for this code. If you did not ask to sign
anything, ignore this message — the code on its own does nothing, and doing nothing applies
no signature.

Thanks,<br>
{{ $brand }}
</x-mail::message>
