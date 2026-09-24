<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReportsRequest;
use App\Models\Branch;
use App\Models\User;
use App\Support\ActiveBranchContext;
use App\Support\BusinessSnapshot;
use App\Support\ReportCsvExport;
use App\Support\SalesAnalytics;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class ReportsController extends Controller
{
    /**
     * Show the read-only Sales report and Store Session summaries for the global Branch scope (a Branch or All Branches).
     * A Branch-scoped account (custom Reports access) must have one of its own assigned Branches selected.
     */
    public function __invoke(ReportsRequest $request, ActiveBranchContext $context, SalesAnalytics $analytics, BusinessSnapshot $snapshot): Response|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $filters = $request->validated();
        $branch = $this->scope($user, $context);
        if ($branch === false) {
            return to_route('workspace');
        }
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
    public function export(ReportsRequest $request, ActiveBranchContext $context, SalesAnalytics $analytics, ReportCsvExport $export): HttpResponse|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $branch = $this->scope($user, $context);
        if ($branch === false) {
            return to_route('workspace');
        }
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

    /**
     * The authorized report scope: the selected Branch, or null (All Branches) for business-wide accounts only. False
     * means a Branch-scoped account has no selected assigned Branch, so it is sent to choose one instead of ever
     * receiving business-wide figures. The selected Branch is already authorized by ActiveBranchContext.
     */
    private function scope(User $user, ActiveBranchContext $context): Branch|false|null
    {
        $branch = $context->current($user);

        return $branch === null && ! $user->hasBusinessWideScope() ? false : $branch;
    }
}
