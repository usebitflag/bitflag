<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="icon" type="image/svg+xml" href="/favicon.svg">

        {{-- Resolves the stored theme before first paint so the page never flashes the wrong theme. --}}
        <script nonce="{{ Vite::cspNonce() }}">
            (function () {
                var theme = 'light';
                try {
                    var stored = window.localStorage.getItem('bitflag-theme');
                    if (stored === 'dark' || (stored === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                        theme = 'dark';
                    }
                } catch (error) {}
                document.documentElement.setAttribute('data-theme', theme);
            })();
        </script>

        <title inertia>{{ config('app.name') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.ts'])
        @inertiaHead
    </head>
    <body>
        @inertia
    </body>
</html>
