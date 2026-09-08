{{--
    Declining is terminal for the whole agreement rather than for one party
    (docs/signing/state-machine.md), so this page says that plainly instead of implying the
    others can carry on. It also says what happens next, which is nothing automatic: a new
    agreement is the sender's decision.
--}}
@extends('layouts.signing')

@section('content')
  <h1 class="text-2xl font-semibold tracking-tight">You declined this agreement</h1>

  <p class="mt-2 text-sm text-muted-foreground">{{ $title }}</p>

  @if ($declinedAt)
    <p class="mt-6 text-sm tabular-nums">
      Declined at {{ $declinedAt->utc()->format('j M Y, H:i:s') }} UTC.
    </p>
  @endif

  @if ($reason)
    <div class="mt-6">
      <p class="text-muted-foreground text-sm">Reason given</p>
      <p class="mt-1 text-sm">{{ $reason }}</p>
    </div>
  @endif

  <p class="mt-8 text-sm">
    Nobody is being asked to sign this agreement any more, and no signature has been applied
    to it. If it was declined by mistake, the sender has to start a new agreement — this one
    cannot be reopened.
  </p>

  @if ($returnUrl)
    <p class="mt-8">
      <a
        href="{{ $returnUrl }}"
        class="inline-flex min-h-11 items-center justify-center rounded-md border border-black/20 px-4 text-base font-medium dark:border-white/20"
      >Return to {{ parse_url($returnUrl, PHP_URL_HOST) }}</a>
    </p>
  @endif
@endsection
