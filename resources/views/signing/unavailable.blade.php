{{--
    Every access refusal renders this, whatever caused it.

    `$reason` is available to the template and is deliberately not printed. The audit trail
    and the tests need to tell "no such invitation" from "that one expired" from "you have
    already signed"; a visitor must not, because the difference is an oracle for which
    envelope identifiers and which tokens exist. It is rendered as an HTML comment so an
    operator debugging with a signer over the phone can ask them to view source, which
    reveals it to somebody who already has the link and to nobody else.

    The one instruction on the page is the one that is always correct.
--}}
@extends('layouts.signing')

@section('content')
  {{-- reason: {{ $reason }} --}}
  <h1 class="text-2xl font-semibold tracking-tight">This link cannot be used</h1>

  @if ($title)
    <p class="mt-2 text-sm text-muted-foreground">{{ $title }}</p>
  @endif

  <p class="mt-6 text-sm">
    The link may have expired, already been used, or been replaced by a newer one. It is also
    possible this agreement is no longer open for signature.
  </p>

  <p class="mt-4 text-sm">
    Ask whoever sent it to send you a new link. Nothing has been signed, and doing nothing
    applies no signature.
  </p>
@endsection
