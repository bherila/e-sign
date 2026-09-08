{{--
    The page an invitation link opens.

    A mail-security scanner will fetch this before the recipient sees it, which is why it is
    a plain read: it shows what the agreement is, who sent it, and who is being asked to
    sign, and the only thing that advances anything is the POST behind the button.

    When the deployment asks for a mailed code, the same template renders the code box —
    `$awaitingCode` — because it is the same step of the same flow and splitting it into a
    second page would mean a second URL carrying the token.
--}}
@extends('layouts.signing')

@section('content')
  <h1 class="text-2xl font-semibold tracking-tight">{{ $title }}</h1>

  <dl class="mt-6 space-y-3 text-sm">
    <div class="flex flex-col gap-0.5">
      <dt class="text-muted-foreground">Sent by</dt>
      <dd class="font-medium">{{ $sender }}</dd>
    </div>
    <div class="flex flex-col gap-0.5">
      <dt class="text-muted-foreground">For</dt>
      <dd class="font-medium">{{ $recipientName }}</dd>
    </div>
    @if ($expiresAt)
      <div class="flex flex-col gap-0.5">
        <dt class="text-muted-foreground">Open until</dt>
        <dd class="font-medium">{{ $expiresAt->timezone(config('app.timezone'))->format('j M Y, H:i T') }}</dd>
      </div>
    @endif
  </dl>

  @if ($notice)
    <p class="mt-6 rounded-md border border-black/10 p-3 text-sm dark:border-white/15">{{ $notice }}</p>
  @endif

  @if ($error)
    <p class="text-destructive mt-6 rounded-md border border-destructive/40 p-3 text-sm" role="alert">{{ $error }}</p>
  @endif

  <form method="POST" action="{{ $startUrl }}" class="mt-8 space-y-4">
    @csrf
    <input type="hidden" name="return" value="{{ $returnCandidate }}">

    @if ($awaitingCode)
      <div class="space-y-2">
        <label for="code" class="block text-sm font-medium">
          Enter the {{ $otpLength }}-digit code we emailed you
        </label>
        <input
          id="code"
          name="code"
          type="text"
          inputmode="numeric"
          autocomplete="one-time-code"
          maxlength="{{ $otpLength }}"
          required
          autofocus
          class="block w-full min-h-11 rounded-md border border-black/20 bg-transparent px-3 text-lg tracking-[0.4em] dark:border-white/20"
        >
        <p class="text-muted-foreground text-xs">
          The code goes to the address this invitation was sent to, not to any address you type
          here. Submitting this form with the box empty sends another code.
        </p>
      </div>
    @endif

    <button
      type="submit"
      class="inline-flex min-h-11 w-full items-center justify-center rounded-md bg-foreground px-4 text-base font-medium text-background sm:w-auto sm:px-8"
    >
      {{ $awaitingCode ? 'Continue' : 'Continue to the agreement' }}
    </button>
  </form>

  <p class="text-muted-foreground mt-8 text-sm">
    Nothing is signed by opening this page. You will see the document and everything you are
    being asked to fill in before you decide.
  </p>
@endsection
