{{--
    The compatibility resolver, `/signing/{recipientPublicId}`.

    Note what is *not* on this page: the agreement's title, the sender's name, the recipient's
    name, and the address on file. Anyone holding the identifier can reach this URL, and the
    identifier is not authorization for anything (docs/HANDOFF.md section 8) — so the page
    reveals nothing about who is contracting with whom.

    The wording after an address is submitted is conditional and means it: "if that address
    matches". The response is byte-identical whether it did or not, which is the whole design.
--}}
@extends('layouts.signing')

@section('content')
  <h1 class="text-2xl font-semibold tracking-tight">Confirm your email address</h1>

  <p class="mt-6 text-sm">
    This link identifies an agreement but does not authorize signing on its own. To continue,
    confirm the address the invitation was sent to; we will email a short code to that address.
  </p>

  @if ($notice)
    <p class="mt-6 rounded-md border border-black/10 p-3 text-sm dark:border-white/15">{{ $notice }}</p>
  @endif

  @if ($error)
    <p class="text-destructive mt-6 rounded-md border border-destructive/40 p-3 text-sm" role="alert">{{ $error }}</p>
  @endif

  @error('email')
    <p class="text-destructive mt-6 rounded-md border border-destructive/40 p-3 text-sm" role="alert">{{ $message }}</p>
  @enderror

  @if ($awaitingCode)
    <form method="POST" action="{{ $verifyUrl }}" class="mt-8 space-y-4">
      @csrf
      <div class="space-y-2">
        <label for="code" class="block text-sm font-medium">
          Enter the {{ $otpLength }}-digit code
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
      </div>
      <button
        type="submit"
        class="inline-flex min-h-11 w-full items-center justify-center rounded-md bg-foreground px-4 text-base font-medium text-background sm:w-auto sm:px-8"
      >Continue</button>
    </form>
  @endif

  <form method="POST" action="{{ $emailUrl }}" class="mt-8 space-y-4">
    @csrf
    <div class="space-y-2">
      <label for="email" class="block text-sm font-medium">Email address</label>
      <input
        id="email"
        name="email"
        type="email"
        autocomplete="email"
        required
        @if (! $awaitingCode) autofocus @endif
        class="block w-full min-h-11 rounded-md border border-black/20 bg-transparent px-3 text-base dark:border-white/20"
      >
    </div>
    <button
      type="submit"
      class="inline-flex min-h-11 w-full items-center justify-center rounded-md border border-black/20 px-4 text-base font-medium sm:w-auto sm:px-8 dark:border-white/20"
    >{{ $awaitingCode ? 'Send another code' : 'Send me a code' }}</button>
  </form>
@endsection
