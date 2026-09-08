@extends('layouts.app')

@section('content')
  <div class="mx-auto w-full max-w-4xl">
    <h1 class="text-2xl font-semibold tracking-tight mb-2">Your workspaces</h1>
    <p class="text-muted-foreground mb-6">
      Read-only. Roles are granted by a workspace owner or administrator.
    </p>

    <div id="workspaces" data-workspaces="{{ json_encode($workspaces, JSON_THROW_ON_ERROR) }}"></div>

    <form method="POST" action="{{ route('logout') }}" class="mt-8">
      @csrf
      <button type="submit" class="text-sm underline underline-offset-4">Sign out</button>
    </form>
  </div>
@endsection

@push('scripts')
  @vite(['resources/js/dashboard.tsx'])
@endpush
