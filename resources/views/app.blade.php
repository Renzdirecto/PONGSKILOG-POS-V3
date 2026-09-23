<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: oklch(1 0 0);
            }

            html.dark {
                background-color: oklch(0.145 0 0);
            }
        </style>

        {{-- Browser tab: round emblem. Home-screen / PWA-ready icons: square logo. Sources in public/images/branding/source --}}
        <link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48">
        <link rel="icon" href="/images/branding/icons/favicon-192.png" type="image/png" sizes="192x192">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png" sizes="180x180">
        <meta name="application-name" content="{{ config('app.name', 'Pongskilog') }}">
        <meta name="apple-mobile-web-app-title" content="{{ config('app.name', 'Pongskilog') }}">
        <meta name="theme-color" content="#111111">
        <meta name="description" content="Pongskilog · Est. 2022">

        {{-- Link previews (Messenger, Facebook, Viber, X) --}}
        <meta property="og:type" content="website">
        <meta property="og:site_name" content="{{ config('app.name', 'Pongskilog') }}">
        <meta property="og:title" content="{{ config('app.name', 'Pongskilog') }} POS System">
        <meta property="og:description" content="Pongskilog POS System · Orders, Kitchen, Inventory and Reports">
        <meta property="og:image" content="{{ asset('images/branding/og-image.jpg') }}">
        <meta property="og:image:type" content="image/jpeg">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta property="og:image:alt" content="Pongskilog POS System">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:image" content="{{ asset('images/branding/og-image.jpg') }}">

        @fonts

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Pongskilog') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
