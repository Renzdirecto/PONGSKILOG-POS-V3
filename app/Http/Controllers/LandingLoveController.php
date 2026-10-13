<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLandingLoveRequest;
use App\Support\LandingLove;
use Illuminate\Http\JsonResponse;

class LandingLoveController extends Controller
{
    /** Adds one batch of taps and answers with the total every visitor now sees. */
    public function __invoke(StoreLandingLoveRequest $request, LandingLove $love): JsonResponse
    {
        return response()->json(['total' => $love->add($request->validated('key'), $request->integer('taps'))])
            ->header('Cache-Control', 'private, no-store');
    }
}
