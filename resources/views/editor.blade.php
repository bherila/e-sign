@extends('layouts.app')

@section('content')
  {{--
    The Blade shell for the visual field editor (issue #22). Same pattern as
    resources/views/dashboard.blade.php: the server writes what the island needs into one
    `data-*` attribute and the client never constructs a URL or guesses a page size.

    One attribute rather than a dozen because the payload is a document — the field schema,
    the page geometry, the URLs — and splitting it across attributes would mean parsing and
    re-validating each one separately. A malformed value degrades to the message below rather
    than to a blank page.

    Progressive enhancement is deliberately partial here: a field editor is a canvas and a
    pointer, and there is no useful no-JavaScript form of it. What the noscript block does is
    say so and point at the canonical JSON export, which is readable without any of this.
  --}}
  <div
    id="field-editor"
    class="mx-auto w-full max-w-[1600px]"
    data-editor="{{ json_encode($editor, JSON_THROW_ON_ERROR) }}"
  >
    <p class="text-muted-foreground text-sm">Loading the field editor…</p>
  </div>

  <noscript>
    <div class="mx-auto mt-4 w-full max-w-[1600px] rounded-md border border-destructive/40 p-4 text-sm">
      The visual field editor needs JavaScript. The field set itself does not: the canonical
      JSON is at
      <a class="underline underline-offset-4" href="{{ $editor['urls']['schema'] }}">
        {{ $editor['urls']['schema'] }}
      </a>
      and can be edited and sent back through the template version API.
    </div>
  </noscript>
@endsection

@push('scripts')
  @vite(['resources/js/editor.tsx'])
@endpush
