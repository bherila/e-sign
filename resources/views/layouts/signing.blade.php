{{--
    The chrome for every guest signing page.

    Not `layouts/app`, and the differences are all deliberate:

    * No navbar. The person reading this has no account, nothing to navigate to, and no
      reason to be shown a sign-in link next to an agreement they are about to execute.
    * No inline script. `layouts/app` sets the colour theme from localStorage in an inline
      `<script>`, which the signing Content-Security-Policy forbids
      (App\Http\Middleware\SigningSecurityHeaders). The theme follows the operating system
      through `color-scheme` and the stylesheet's own `prefers-color-scheme` rules instead,
      which needs no script at all.
    * No third-party origin of any kind — no font CDN, no analytics, no icon service.
      AGENTS.md forbids them here, and the CSP would refuse them anyway.

    The viewport meta sets `width=device-width, initial-scale=1` and stops there. It names no
    `maximum-scale` and no `user-scalable=no`, so pinch-zoom works: a signer reading a
    contract on a phone has to be able to magnify the small print, and blocking that on a
    page whose entire purpose is informed agreement would be indefensible.

    The layout is a single column that is comfortable at 375px and caps its measure on a
    desktop. Interactive controls carry a 44px minimum touch target through the `.signing-*`
    classes in resources/css/app.css.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
  <head>
    @viteReactRefresh
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- Belt to the header's braces: a page saved to disk keeps the instruction. --}}
    <meta name="referrer" content="no-referrer">
    <meta name="color-scheme" content="dark light">
    <title>{{ $title ?? 'Review and sign' }} — {{ config('app.name') }}</title>
    @vite(['resources/css/app.css'])
    @stack('head')
  </head>
  <body class="min-h-screen bg-background text-foreground flex flex-col">
    <header class="border-b border-black/10 dark:border-white/10">
      <div class="mx-auto w-full max-w-3xl px-4 py-3 sm:px-6">
        <p class="text-muted-foreground text-xs uppercase tracking-wide">{{ config('app.name') }}</p>
      </div>
    </header>

    <main class="flex-1">
      <div class="mx-auto w-full max-w-3xl px-4 py-6 sm:px-6">
        @yield('content')
      </div>
    </main>

    <footer class="border-t border-black/10 py-6 text-center text-xs text-muted-foreground dark:border-white/10">
      <p>
        Electronic signature service. People sign; the finished PDF is sealed with this
        service's own certificate.
      </p>
    </footer>

    @stack('scripts')
  </body>
</html>
