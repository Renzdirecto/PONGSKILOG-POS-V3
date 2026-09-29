<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Appearance (Phase 20): Light (default) or Dark, from this device's `appearance` cookie. Anything else — including a
 * legacy "system" — is Light, and customer-facing pages are always Light whatever the device chose.
 */
class HandleAppearance
{
    /** @var list<string> */
    public const CUSTOMER_FACING_ROUTES = ['home', 'qr.*', 'kiosk.*', 'receipt.*', 'workspaces.customer-display', 'customer-screen.*', 'pickup.*'];

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locked = $request->routeIs(...self::CUSTOMER_FACING_ROUTES);
        View::share('appearance', ! $locked && $request->cookie('appearance') === 'dark' ? 'dark' : 'light');
        View::share('appearanceLocked', $locked);

        return $next($request);
    }
}
