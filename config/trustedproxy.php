<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Behind an HTTPS reverse proxy (Railway / Cloudflare in production, or a
    | local HTTPS tunnel for installed-app testing on a phone) Laravel must
    | trust the proxy's X-Forwarded-* headers, or it builds http:// URLs that
    | an https page, and so the installed PWA, cannot load. Comma-separated
    | addresses, or * to trust the calling proxy. Unset: no proxy is trusted.
    | Only X-Forwarded-For and X-Forwarded-Proto are read (bootstrap/app.php);
    | the host always comes from the Host header, never X-Forwarded-Host.
    |
    */

    'proxies' => env('TRUSTED_PROXIES'),

];
