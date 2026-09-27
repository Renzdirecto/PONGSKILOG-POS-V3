<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

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

            /* Installed-app startup screen: only when launched as an app, removed as soon as React renders. */
            #pwa-boot {
                display: none;
            }

            @media (display-mode: standalone), (display-mode: fullscreen), (display-mode: minimal-ui) {
                #pwa-boot {
                    position: fixed;
                    inset: 0;
                    z-index: 2147483647;
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                    justify-content: center;
                    gap: 14px;
                    background: #111111;
                    color: #ffffff;
                    font: 700 13px/1 Poppins, ui-sans-serif, system-ui, sans-serif;
                    letter-spacing: 0.14em;
                    animation: pwa-boot-out 0.2s ease 8s forwards;
                }

                #pwa-boot img {
                    width: 88px;
                    height: 88px;
                    border-radius: 50%;
                }

                #pwa-boot span {
                    display: block;
                    width: 72px;
                    height: 3px;
                    overflow: hidden;
                    border-radius: 3px;
                    background: rgb(255 255 255 / 15%);
                }

                #pwa-boot span::after {
                    content: '';
                    display: block;
                    width: 40%;
                    height: 100%;
                    border-radius: 3px;
                    background: #f5c542;
                    animation: pwa-boot-bar 1.1s ease-in-out infinite;
                }

                @media (prefers-reduced-motion: reduce) {
                    #pwa-boot span::after {
                        animation: none;
                    }
                }
            }

            @keyframes pwa-boot-bar {
                from { transform: translateX(-100%); }
                to { transform: translateX(250%); }
            }

            /* Never leave the startup screen over the app if scripts failed to load. */
            @keyframes pwa-boot-out {
                to { visibility: hidden; opacity: 0; }
            }
        </style>

        {{-- Browser tab: the rounded-square chef icon. Home-screen / PWA-ready icons: square logo. Sources in public/images/branding/source --}}
        <link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48">
        <link rel="icon" href="/images/branding/icons/favicon-192.png" type="image/png" sizes="192x192">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png" sizes="180x180">
        <meta name="application-name" content="{{ config('app.name', 'Pongskilog') }}">
        <meta name="apple-mobile-web-app-title" content="PONGSKILOG">
        <meta name="theme-color" content="#111111">
        {{-- Installable staff app (PWA Phase 1). Public Customer QR, receipt, customer screen and pickup pages are not part of it. --}}
        @unless (request()->routeIs('qr.*', 'kiosk.*', 'receipt.*', 'customer-screen.*', 'pickup.*'))
            <link rel="manifest" href="/manifest.webmanifest">
            <meta name="mobile-web-app-capable" content="yes">
            <meta name="apple-mobile-web-app-capable" content="yes">
            <meta name="apple-mobile-web-app-status-bar-style" content="black">
        @endunless
        <meta name="description" content="Pongskilog · Est. 2022">

        {{-- Link previews (Messenger, Facebook, Viber, X) --}}
        <meta property="og:type" content="website">
        <meta property="og:site_name" content="{{ config('app.name', 'Pongskilog') }}">
        <meta property="og:title" content="{{ config('app.name', 'Pongskilog') }}">
        <meta property="og:description" content="Pongskilog · Est. 2022">
        <meta property="og:image" content="{{ asset('images/branding/og-image.jpg') }}">
        <meta property="og:image:type" content="image/jpeg">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta property="og:image:alt" content="Pongskilog logo: a chef tossing fried rice in a wok, est. 2022">
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
        @unless (request()->routeIs('qr.*', 'kiosk.*', 'receipt.*', 'customer-screen.*', 'pickup.*'))
            <div id="pwa-boot" aria-hidden="true">
                <img src="/images/branding/pongskilog-emblem.png" alt="" width="88" height="88">
                PONGSKILOG
                <span></span>
            </div>
        @endunless
        <x-inertia::app />
    </body>
</html>
