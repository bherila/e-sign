{{--
    A decline refused because this agreement does not allow its signers to decline (issue #165).

    Reached only by a decline posted anyway: the signing page does not offer the button when
    the setting is off. Nothing was recorded, and the page says how the agreement can be
    stopped instead, because "you cannot" with no next step is not an answer.
--}}
@extends('layouts.signing')

@section('content')
  <h1 class="text-2xl font-semibold tracking-tight">This agreement cannot be declined here</h1>

  @if ($title)
    <p class="mt-2 text-sm text-muted-foreground">{{ $title }}</p>
  @endif

  <p class="mt-6 text-sm">
    The sender has set this agreement so that it cannot be declined from the signing page.
    Nothing has been recorded. If you do not want to sign, contact the sender: they can
    cancel the agreement.
  </p>

  <p class="mt-4 text-sm">
    <a class="underline" href="{{ $sessionUrl }}">Return to the agreement</a>
  </p>
@endsection
