<?php

namespace App\Http\Controllers;

use App\Http\Requests\OwnerDashboardRequest;
use App\Models\User;
use App\Support\ActiveBranchContext;
use App\Support\BusinessSnapshot;
use App\Support\SalesAnalytics;
use Inertia\Inertia;
use Inertia\Response;

class OwnerDashboardController extends Controller
{
    /** The Dashboard lists only the most recent Store Sessions; Reports lists them all. */
    private const SESSION_LIMIT = 4;

    /**
     * The business Dashboard for the global Branch scope: the same analytics as Reports plus live operating state.
     */
    public function __invoke(OwnerDashboardRequest $request, ActiveBranchContext $context, SalesAnalytics $analytics, BusinessSnapshot $snapshot): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $branch = $context->current($user);
        $period = $request->validated('period') ?? 'today';
        $result = $analytics->for($branch, ['date' => $period]);
        $report = $result['report'];

        return Inertia::render('workspaces/owner-dashboard', [
            'period' => $period,
            'analytics' => $result['analytics'],
            'report' => [
                'period' => $report['period'],
                'scope' => $report['scope'],
                'summary' => $report['summary'],
                'sessions' => array_slice(array_reverse($report['sessions']), 0, self::SESSION_LIMIT),
                'sessions_total' => $report['sessions_listed']['total'],
            ],
            'kitchen' => $snapshot->kitchen($branch),
            'inventory' => $user->hasPermission('inventory.manage') ? $snapshot->inventoryAttention($branch) : null,
            'recentTransactions' => $user->hasPermission('transactions.view') ? $snapshot->recentTransactions($branch) : [],
        ]);
    }
}
