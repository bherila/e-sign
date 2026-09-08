{{--
    409. The acceptance quoted a review that is no longer what the envelope holds.

    docs/ARCHITECTURE.md invariant 2: an acceptance binds to specific material values, a
    specific envelope version, and a specific consent version, and a stale submission fails
    and requires re-review. The remedy is never automatic — the page asks the person to look
    at what changed, because agreeing again without reading is exactly what the invariant
    exists to prevent.

    The reload is a link and not a meta-refresh or a script. A page that reloaded itself
    would put the signer back in front of the same button with no idea anything had moved.
--}}
@extends('layouts.signing')

@section('content')
  {{-- reason: {{ $reason }} --}}
  <h1 class="text-2xl font-semibold tracking-tight">The agreement changed while you were reading it</h1>

  <p class="mt-2 text-sm text-muted-foreground">{{ $title }}</p>

  <p class="mt-6 text-sm">
    Your signature was not applied. Something in the agreement moved after the copy you were
    shown was loaded — a value another party filled in, a correction from the sender, or the
    consent notice being updated.
  </p>

  <p class="mt-4 text-sm">
    Please open the current version and read it again before signing. Anything you had
    already filled in is still saved.
  </p>

  <p class="mt-8">
    <a
      href="{{ $reloadUrl }}"
      class="inline-flex min-h-11 items-center justify-center rounded-md bg-foreground px-6 text-base font-medium text-background"
    >Review the current version</a>
  </p>
@endsection
