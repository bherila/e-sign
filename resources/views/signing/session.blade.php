{{--
    The signing page itself: a Blade shell around one React island, in the pattern
    resources/views/editor.blade.php establishes. The server writes everything the island
    needs into one `data-*` attribute — the field schema, the page geometry, the consent
    text, the URLs, the digests the acceptance has to quote — so the client never constructs
    a URL, never guesses a page size, and never decides who owns a field.

    A malformed payload degrades to a message rather than a blank page, and specifically not
    to an *empty* document: a signing page that opened with no fields would invite somebody
    to accept an agreement they had not been shown.

    There is no useful no-JavaScript form of this page. Drawing a signature, overlaying
    fields on a rendered PDF, and re-encoding an image client-side all need a browser that
    runs scripts. What the noscript block does is say so, and say the two things that remain
    true without it: nothing has been signed, and the sender can be asked for another route.
--}}
@extends('layouts.signing')

@section('content')
  <div
    id="signing-page"
    class="w-full"
    data-signing="{{ json_encode($signing, JSON_THROW_ON_ERROR) }}"
  >
    <p class="text-muted-foreground text-sm">Loading the agreement…</p>
  </div>

  <noscript>
    <div class="mt-4 rounded-md border border-destructive/40 p-4 text-sm">
      <p class="font-medium">This page needs JavaScript.</p>
      <p class="mt-2">
        Reviewing and signing needs the document rendered in your browser, so there is no
        version of this page that works without scripts. Nothing has been signed. Ask
        {{ $signing['envelope']['sender'] }} to send the agreement another way.
      </p>
    </div>
  </noscript>
@endsection

@push('scripts')
  @vite(['resources/js/signing.tsx'])
@endpush
