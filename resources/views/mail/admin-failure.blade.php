{{-- To operators. No link, no stack trace: see App\Mail\AdminFailureMail. --}}
<x-mail::message>
# {{ $brand }} needs attention

{{ $context->failureSummary }}

**Reference:** {{ $context->reference }}

@if ($context->reason)
**Reported detail:** {{ $context->reason }}
@endif

Nothing was completed, published, or marked as delivered as a result of this failure. It stays in this state until somebody acts on it.

Sign in to {{ $brand }} and look up the reference above. This message deliberately carries no link.
</x-mail::message>
