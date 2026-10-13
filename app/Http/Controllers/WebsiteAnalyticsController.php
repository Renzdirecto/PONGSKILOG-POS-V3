<?php

namespace App\Http\Controllers;

use App\Http\Requests\WebsiteAnalyticsRequest;
use App\Models\Branch;
use App\Models\CustomerReview;
use App\Models\LandingTeamMember;
use App\Models\User;
use App\Support\ReportPeriod;
use App\Support\WebsiteAnalytics;
use Inertia\Inertia;
use Inertia\Response;

class WebsiteAnalyticsController extends Controller
{
    /**
     * The Owner's Website workspace (v1.3.0): Analytics (read-only counters of the public landing page), Reviews (the
     * moderation moved here from Settings) and Team (the landing page's six cards). The website belongs to the whole
     * business, never to one Branch, so only business-wide accounts are authorized (see the request) and the selected
     * Branch narrows nothing. A tab the account may not use gets no data.
     */
    public function __invoke(WebsiteAnalyticsRequest $request, WebsiteAnalytics $analytics): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $period = ReportPeriod::fromFilters([
            'date' => $request->validated('period') ?? 'today',
            'from' => $request->validated('from'),
            'to' => $request->validated('to'),
        ]);
        $can = [
            'analytics' => $user->hasPermission('reports.view'),
            'reviews' => $user->can('viewAny', CustomerReview::class),
            'team' => $user->can('viewAny', LandingTeamMember::class),
        ];

        return Inertia::render('workspaces/website-analytics', [
            'can' => $can,
            'report' => fn (): ?array => $can['analytics'] ? $analytics->report($period) : null,
            'reviewBranches' => fn (): array => $can['reviews'] ? Branch::query()->orderBy('name')->orderBy('code')->get(['id', 'name', 'code'])->all() : [],
            'team' => fn (): array => $can['team'] ? LandingTeamMember::query()->orderBy('position')->get()->map->card()->all() : [],
        ]);
    }
}
