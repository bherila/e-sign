{{--
    429, with a Retry-After header set by the controller from the limiter itself.

    It does not say which ceiling was reached. Telling a prober whether it tripped the
    per-address or the per-client limit tells it how to spread the next attempt; telling a
    signer the same thing tells them nothing they can act on.
--}}
@extends('layouts.signing')

@section('content')
  <h1 class="text-2xl font-semibold tracking-tight">Too many attempts</h1>

  @if ($title)
    <p class="mt-2 text-sm text-muted-foreground">{{ $title }}</p>
  @endif

  <p class="mt-6 text-sm">
    This has been tried too many times recently. Wait
    {{ max(1, (int) ceil($retry_after / 60)) }}
    {{ \Illuminate\Support\Str::plural('minute', max(1, (int) ceil($retry_after / 60))) }}
    and try again.
  </p>

  <p class="mt-4 text-sm">Nothing has been signed, and nothing has been lost.</p>
@endsection
