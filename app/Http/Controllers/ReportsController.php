<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReportsRequest;
use App\Models\User;
use App\Support\ActiveBranchContext;
use App\Support\StoreSessionSalesReport;
use Inertia\Inertia;
use Inertia\Response;

class ReportsController extends Controller
{
    /**
     * Show the read-only Sales & Store Sessions report for the global Branch scope (a Branch or All Branches).
     */
    public function __invoke(ReportsRequest $request, ActiveBranchContext $context, StoreSessionSalesReport $report): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        /** @var array{date?: string|null, from?: string|null, to?: string|null, session?: string|null} $filters */
        $filters = $request->validated();

        return Inertia::render('workspaces/reports', [
            'report' => $report->for($context->current($user), $filters),
            'filters' => $filters,
        ]);
    }
}
