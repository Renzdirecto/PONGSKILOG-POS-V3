<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\ActiveBranchContext;
use App\Support\CashierDashboard;
use App\Support\PosAccess;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CashierDashboardController extends Controller
{
    /**
     * Show the read-only operational overview of the active Branch and its current Store Session.
     */
    public function __invoke(Request $request, ActiveBranchContext $context, PosAccess $access, CashierDashboard $dashboard): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $branch = $context->current($user);
        abort_if($branch === null, 403);
        $access->authorize($user, $branch);

        return Inertia::render('workspaces/cashier-dashboard', [
            'dashboard' => fn (): array => $dashboard->for($branch),
        ]);
    }
}
