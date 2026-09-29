<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline browser security headers for every web response (Phase 20). A response that already chose its own value
 * (e.g. the public pages' `no-referrer`) keeps it.
 *
 * - no framing by other sites (clickjacking on Void, Staff password reset, payments);
 * - no MIME sniffing of uploads or downloads;
 * - no full URLs (signed receipt links, pickup capabilities) leaked to other sites in the Referer header;
 * - HSTS only on HTTPS, so a LAN http:// development link keeps working.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        foreach ([
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
        ] as $name => $value) {
            if (! $headers->has($name)) {
                $headers->set($name, $value);
            }
        }
        if ($request->isSecure() && ! $headers->has('Strict-Transport-Security')) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
