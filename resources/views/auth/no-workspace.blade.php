@extends('layouts.app')

@section('content')
  <div class="mx-auto w-full max-w-2xl">
    <h1 class="text-2xl font-semibold tracking-tight mb-2">You do not belong to a workspace yet</h1>
    <p class="text-muted-foreground">
      Signing in worked. That is not the same as having access to anything: workspace
      membership is granted separately, and nobody has granted you one. Ask a workspace owner
      to add you.
    </p>
    <p class="text-muted-foreground mt-4">
      Setting this installation up for the first time? The first owner is provisioned from the
      command line with <code class="font-mono text-sm">esign:bootstrap-owner</code>; see the
      bootstrap runbook. No account becomes an administrator by signing in.
    </p>

    {{-- Server-rendered: there is nothing interactive on this page to hydrate. --}}
    <form method="POST" action="{{ route('logout') }}" class="mt-8">
      @csrf
      <button type="submit" class="text-sm underline underline-offset-4">Sign out</button>
    </form>
  </div>
@endsection
