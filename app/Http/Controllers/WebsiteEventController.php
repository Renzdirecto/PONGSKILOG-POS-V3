<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWebsiteEventRequest;
use App\Support\WebsiteAnalytics;
use Illuminate\Http\Response;

class WebsiteEventController extends Controller
{
    /** Always answers 204, so the landing page never learns whether its event was counted. */
    public function __invoke(StoreWebsiteEventRequest $request, WebsiteAnalytics $analytics): Response
    {
        if ($analytics->counts($request)) {
            $analytics->record($request, $request->validated('event'));
        }

        return response()->noContent();
    }
}
