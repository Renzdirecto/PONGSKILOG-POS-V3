<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\ActiveBranchContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveBranchContext
{
    public function __construct(private ActiveBranchContext $activeBranchContext) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $this->activeBranchContext->current($user) === null) {
            return to_route('workspace');
        }

        return $next($request);
    }
}
