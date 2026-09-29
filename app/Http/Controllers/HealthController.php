<?php

namespace App\Http\Controllers;

use App\Support\HealthChecks;
use App\Support\ReleaseInfo;
use Illuminate\Http\JsonResponse;

/**
 * Readiness for monitoring (`GET /health`): 503 when a critical dependency (database, cache) fails, 200 with
 * `degraded` when only realtime, queue or media dependencies fail. `/up` stays the plain liveness probe for deploys.
 * Registered outside the web middleware: no session, cookies or shared props, and nothing is written.
 */
class HealthController extends Controller
{
    public function __invoke(HealthChecks $health): JsonResponse
    {
        $result = $health->run();

        return response()->json([
            'status' => $result['status'],
            'release' => ReleaseInfo::current(),
            'checked_at' => now()->toIso8601String(),
            'checks' => $result['checks'],
        ], $result['status'] === 'fail' ? 503 : 200)->header('Cache-Control', 'no-store');
    }
}
