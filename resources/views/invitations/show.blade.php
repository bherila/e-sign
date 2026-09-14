@extends('layouts.app')

@section('content')
  {{-- Server-rendered: one decision, one button, and nothing that runs on GET. --}}
  <div class="mx-auto w-full max-w-xl">
    @if ($invitation && $workspace)
      <h1 class="text-2xl font-semibold tracking-tight mb-2">Join {{ $workspace->name }}</h1>
      <p class="mb-4">
        You have been invited to join this workspace as
        <strong>{{ $invitation->role->label() }}</strong>. The invitation expires
        {{ $invitation->expires_at->utc()->format('j F Y, H:i') }} UTC and can be used once.
      </p>
      <p class="text-muted-foreground mb-6">
        Accepting adds the workspace to the account you are signed in with now,
        <strong>{{ $account->name }}</strong>. If the invitation was meant for a different account,
        sign out and open the link again.
      </p>

      @error('invitation')
        <p role="alert" class="mb-6 rounded border border-red-300 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:text-red-300">{{ $message }}</p>
      @enderror

      @if ($alreadyMember)
        <p class="mb-6">You are already a member of this workspace, so there is nothing to accept.</p>
        <a href="{{ route('dashboard') }}" class="underline underline-offset-4">Go to your workspaces</a>
      @else
        <form method="POST" action="{{ route('invitations.redeem') }}">
          @csrf
          <input type="hidden" name="invitation" value="{{ $invitation->public_id }}">
          <button type="submit" class="rounded bg-primary px-4 py-2 text-sm font-medium text-primary-foreground">Accept invitation</button>
        </form>
      @endif
    @else
      <h1 class="text-2xl font-semibold tracking-tight mb-2">This invitation cannot be used</h1>
      <p class="text-muted-foreground mb-6">
        It may have expired, been revoked or already been accepted. Ask the person who sent it for a
        new link.
      </p>
      <a href="{{ route('dashboard') }}" class="underline underline-offset-4">Go to your workspaces</a>
    @endif
  </div>
@endsection
