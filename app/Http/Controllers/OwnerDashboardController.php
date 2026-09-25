<?php

namespace App\Http\Controllers;

use App\Http\Requests\OwnerDashboardRequest;
use App\Models\User;
use App\Support\ActiveBranchContext;
use App\Support\BusinessSnapshot;
use App\Support\SalesAnalytics;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class OwnerDashboardController extends Controller
{
    /** The Dashboard lists only the most recent Store Sessions; Reports lists them all. */
    private const SESSION_LIMIT = 4;

    /**
     * The Dashboard for the global Branch scope: the same analytics as Reports plus live operating state. A
     * Branch-scoped account without a selected assigned Branch is sent to choose one, never shown All Branches.
     */
    public function __invoke(OwnerDashboardRequest $request, ActiveBranchContext $context, SalesAnalytics $analytics, BusinessSnapshot $snapshot): Response|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $branch = $context->managementBranch($user);
        if ($branch === false) {
            return to_route('workspace');
        }
        $period = $request->validated('period') ?? 'today';
        /** Lazy and computed once: a period switch or a realtime partial reload runs only the queries its props need. */
        $sales = null;
        $result = function () use (&$sales, $analytics, $branch, $period): array {
            return $sales ??= $analytics->for($branch, ['date' => $period]);
        };

        return Inertia::render('workspaces/owner-dashboard', [
            'period' => $period,
            'analytics' => fn (): array => $result()['analytics'],
            'report' => function () use ($result): array {
                $report = $result()['report'];

                return [
                    'period' => $report['period'],
                    'scope' => $report['scope'],
                    'summary' => $report['summary'],
                    'sessions' => array_slice(array_reverse($report['sessions']), 0, self::SESSION_LIMIT),
                    'sessions_total' => $report['sessions_listed']['total'],
                ];
            },
            'kitchen' => fn (): array => $snapshot->kitchen($branch),
            'inventory' => fn (): ?array => $user->hasPermission('inventory.manage') ? $snapshot->inventoryAttention($branch) : null,
            'recentTransactions' => fn (): array => $user->hasPermission('transactions.view') ? $snapshot->recentTransactions($branch) : [],
        ]);
    }
}
