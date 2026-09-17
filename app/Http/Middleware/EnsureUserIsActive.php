<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $authenticatedUser = $request->user();

        if ($authenticatedUser !== null) {
            $this->ensureActive($request, $authenticatedUser);
        }

        $response = $next($request);

        if ($authenticatedUser === null) {
            $newlyAuthenticatedUser = Auth::user();

            if ($newlyAuthenticatedUser !== null) {
                $this->ensureActive($request, $newlyAuthenticatedUser);
            }
        }

        return $response;
    }

    private function ensureActive(Request $request, Authenticatable $user): void
    {
        $isActive = $user instanceof User
            && (bool) User::query()->whereKey($user->getKey())->value('is_active');

        if ($isActive) {
            return;
        }

        Auth::logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        throw new AuthenticationException;
    }
}
