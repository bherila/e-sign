@extends('layouts.app')

@section('content')
  <div class="mx-auto w-full max-w-4xl">
    <p class="text-sm mb-2"><a href="{{ route('dashboard') }}" class="underline underline-offset-4">Your workspaces</a></p>
    <h1 class="text-2xl font-semibold tracking-tight mb-2">Members of {{ $workspace->name }}</h1>
    <p class="text-muted-foreground mb-6">
      Invite someone with a single-use link, change a role, or remove access. Removing someone ends
      their access and nothing else: envelopes, documents and the audit trail stay exactly as they are.
    </p>

    <div id="members" data-members="{{ json_encode($payload, JSON_THROW_ON_ERROR) }}"></div>
    <noscript>This page needs JavaScript to manage members.</noscript>
  </div>
@endsection

@push('scripts')
  @vite(['resources/js/members.tsx'])
@endpush
