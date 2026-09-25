<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * The PWA service worker, served at the site root so its scope covers the whole application. The file comes from the
 * production build (`public/build/sw.js`) and is sent with no-cache headers: browsers and CDNs must always revalidate
 * it, or a new version would never be detected. The route runs outside the web middleware (no session, cookies or
 * CSRF). While the Vite dev server runs, or before a build exists, there is no service worker.
 */
class ServiceWorkerController extends Controller
{
    public function __invoke(): Response
    {
        $script = public_path('build/sw.js');

        abort_if(is_file(public_path('hot')) || ! is_file($script), 404);

        return response((string) file_get_contents($script), 200, [
            'Content-Type' => 'text/javascript; charset=utf-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Service-Worker-Allowed' => '/',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
