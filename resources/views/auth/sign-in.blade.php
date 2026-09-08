@extends('layouts.app')

@section('content')
  <div class="mx-auto w-full max-w-md">
    <h1 class="text-2xl font-semibold tracking-tight mb-6">Sign in to {{ config('app.name') }}</h1>

    {{--
      The interactive part is a React island. The heading above is server-rendered so the
      page identifies itself without JavaScript, and every value the island needs is an
      attribute here rather than a global — nothing about the deployment is compiled into
      the bundle.
    --}}
    <div
      id="sign-in"
      data-mode="{{ $mode }}"
      data-sso-url="{{ $ssoUrl }}"
      data-login-url="{{ $loginUrl }}"
      data-csrf-token="{{ csrf_token() }}"
      data-email="{{ old('email') }}"
      data-error="{{ $errors->first('email') ?: $errors->first('password') }}"
      data-status="{{ session('status') }}"
    ></div>
  </div>
@endsection

@push('scripts')
  @vite(['resources/js/sign-in.tsx'])
@endpush
