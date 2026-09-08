{{--
    The confirmation, rendered from the recipient's own state rather than from a flash
    message, so a reload, a bookmark, and a printed copy all show the same thing.

    The wording is bound by AGENTS.md's honest-language rule. The person provided an
    electronic signature; the service seals the finished PDF under its own certificate. This
    page says both, says which time is the authoritative one, and promises a sealed copy only
    once everyone has signed — because a completion event is published only after the final
    PDF exists, is validated, and is retrievable.
--}}
@extends('layouts.signing')

@section('content')
  <h1 class="text-2xl font-semibold tracking-tight">You have signed this agreement</h1>

  <p class="mt-2 text-sm text-muted-foreground">{{ $title }}</p>

  <dl class="mt-6 space-y-3 text-sm">
    <div class="flex flex-col gap-0.5">
      <dt class="text-muted-foreground">Signed as</dt>
      <dd class="font-medium">{{ $recipientName }}</dd>
    </div>
    @if ($acceptedAt)
      <div class="flex flex-col gap-0.5">
        <dt class="text-muted-foreground">Accepted at (server time, UTC)</dt>
        <dd class="font-medium tabular-nums">{{ $acceptedAt->utc()->format('j M Y, H:i:s') }} UTC</dd>
      </div>
    @endif
    @if ($attestationId)
      <div class="flex flex-col gap-0.5">
        <dt class="text-muted-foreground">Record reference</dt>
        <dd class="font-mono text-xs break-all">{{ $attestationId }}</dd>
      </div>
    @endif
  </dl>

  <div class="mt-8 space-y-3 text-sm">
    @if ($everyoneSigned)
      <p>
        Everyone has now signed. The finished PDF is being produced and sealed with this
        service's certificate; you will be emailed a copy once it exists and has been checked.
      </p>
    @else
      <p>
        Other parties still have to sign. Once they have, the finished PDF is produced and
        sealed with this service's certificate, and you will be emailed a copy.
      </p>
    @endif

    <p class="text-muted-foreground">
      The seal belongs to this service, not to you. It lets a reader detect whether the
      finished document has been altered afterwards; it is not a personal signing certificate
      and does not represent an eIDAS advanced or qualified electronic signature.
    </p>
  </div>

  @if ($returnUrl)
    <p class="mt-8">
      <a
        href="{{ $returnUrl }}"
        class="inline-flex min-h-11 items-center justify-center rounded-md border border-black/20 px-4 text-base font-medium dark:border-white/20"
      >Return to {{ parse_url($returnUrl, PHP_URL_HOST) }}</a>
    </p>
  @endif
@endsection
