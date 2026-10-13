<?php

namespace App\Http\Controllers;

use App\Http\Requests\WebsiteAnalyticsRequest;
use App\Support\ReportPeriod;
use App\Support\WebsiteAnalytics;
use Inertia\Inertia;
use Inertia\Response;

class WebsiteAnalyticsController extends Controller
{
    /**
     * Read-only counters of the public landing page. The website belongs to the whole business, never to one Branch,
     * so the selected Branch does not narrow it and only business-wide accounts are authorized (see the request).
     */
    public function __invoke(WebsiteAnalyticsRequest $request, WebsiteAnalytics $analytics): Response
    {
        $period = ReportPeriod::fromFilters([
            'date' => $request->validated('period') ?? 'today',
            'from' => $request->validated('from'),
            'to' => $request->validated('to'),
        ]);

        return Inertia::render('workspaces/website-analytics', [
            'report' => fn (): array => $analytics->report($period),
        ]);
    }
}
