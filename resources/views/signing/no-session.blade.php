{{--
    Shown when a signing route was reached without a live session for the envelope in the
    URL: no cookie, an expired one, or one belonging to a different agreement.

    It cannot offer a link back to the landing page, because that URL contains the invitation
    credential and the server does not have it — only its verifier. So the page says to open
    the link from the invitation again, which is both the correct instruction and the one
    that reveals nothing.
--}}
@extends('layouts.signing')

@section('content')
  {{-- reason: {{ $reason }} --}}
  <h1 class="text-2xl font-semibold tracking-tight">Your signing session has ended</h1>

  <p class="mt-2 text-sm text-muted-foreground">{{ $title }}</p>

  <p class="mt-6 text-sm">
    Signing sessions do not last indefinitely, and they do not survive being opened on a
    different device or in a different browser.
  </p>

  <p class="mt-4 text-sm">
    Open the link in your invitation email again to start a new one. If that link no longer
    works, ask the sender for a fresh one. Anything you had already saved is still there, and
    nothing has been signed.
  </p>
@endsection
