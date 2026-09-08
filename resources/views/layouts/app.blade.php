<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
  <head>
    @viteReactRefresh
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name') }}</title>
    <meta name="color-scheme" content="dark light">
    <script>
      (function() {
        try {
          var theme = localStorage.getItem('theme') || 'system';
          var d = document.documentElement;
          var isDark = theme === 'dark' || (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
          if (isDark) d.classList.add('dark'); else d.classList.remove('dark');
        } catch (e) { /* no-op */ }
      })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/navbar.tsx'])
    @stack('head')
    <script>(_=>{let a})()</script>
  </head>
  <body class="min-h-screen flex flex-col">
    <header class="site-header border-b border-gray-200 dark:border-[#3E3E3A] h-14">
      {{--
        Only `data-authenticated`. The template's `user_role === 'Admin'` check was dead: this
        application has no `user_role` column and no global administrator, by design. Authority
        is a workspace membership row (see App\Domain\Identity), so an "is admin" flag in the
        chrome would be a claim nothing can back up.
      --}}
      <div id="navbar" data-authenticated="{{ auth()->check() ? 'true' : 'false' }}"></div>
    </header>

    <main class="flex-1 px-4 py-6 sm:px-6 lg:px-8">
      @yield('content')
    </main>

    <footer class="border-t border-gray-200 dark:border-[#3E3E3A] py-6 text-sm text-center text-gray-600 dark:text-[#A1A09A]">
      © {{ date('Y') }}
    </footer>

    @stack('scripts')
  </body>
</html>
