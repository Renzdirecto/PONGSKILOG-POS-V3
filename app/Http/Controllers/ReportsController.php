<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReportsRequest;
use App\Models\User;
use App\Support\ActiveBranchContext;
use App\Support\BusinessSnapshot;
use App\Support\ReportCsvExport;
use App\Support\SalesAnalytics;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class ReportsController extends Controller
{
    /**
     * Show the read-only Sales report and Store Session summaries for the global Branch scope (a Branch or All Branches).
     */
    public function __invoke(ReportsRequest $request, ActiveBranchContext $context, SalesAnalytics $analytics, BusinessSnapshot $snapshot): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $filters = $request->validated();
        $branch = $context->current($user);
        $result = $analytics->for($branch, $filters);

        return Inertia::render('workspaces/reports', [
            'report' => $result['report'],
            'analytics' => $result['analytics'],
            'kitchenNow' => fn () => $snapshot->kitchen($branch),
            'filters' => $filters,
        ]);
    }

    /**
     * Download the same filtered, Branch-scoped report as CSV. The rows come from the authorized report arrays only.
     */
    public function export(ReportsRequest $request, ActiveBranchContext $context, SalesAnalytics $analytics, ReportCsvExport $export): HttpResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $branch = $context->current($user);
        $result = $analytics->for($branch, $request->validated());
        $period = $result['report']['period'];
        $name = implode('-', array_filter([
            'pongskilog-report',
            $branch?->code === null ? 'all-branches' : strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $branch->code)),
            $period['from'],
            $period['from'] === $period['to'] ? null : $period['to'],
        ])).'.csv';
        $csv = $export->csv($export->rows($result['report'], $result['analytics'], $analytics->filterLabels($result['analytics'])));

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'X-Generated-At' => CarbonImmutable::now('Asia/Manila')->toIso8601String(),
        ]);
    }
}
