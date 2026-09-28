<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * Named limiters for Fortify's own account-recovery routes, which Fortify registers without any (Phase 20):
 * requesting a reset link, resetting a password, and confirming the password before a sensitive screen. Added to
 * every Fortify route through `fortify.middleware`; other Fortify routes pass straight through.
 */
class ThrottleAccountRecovery
{
    /** @var array<string, string> route name => named limiter (App\Support\RateLimits) */
    public const LIMITERS = [
        'password.email' => 'password-email',
        'password.update' => 'password-reset',
        'password.confirm.store' => 'password-confirm',
    ];

    public function __construct(private ThrottleRequests $throttle) {}

    public function handle(Request $request, Closure $next): Response
    {
        $limiter = self::LIMITERS[$request->route()?->getName() ?? ''] ?? null;

        return $limiter === null ? $next($request) : $this->throttle->handle($request, $next, $limiter);
    }
}
