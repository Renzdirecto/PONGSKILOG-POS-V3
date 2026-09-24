<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\ActiveBranchContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceController extends Controller
{
    public function __invoke(Request $request, ActiveBranchContext $activeBranchContext): RedirectResponse|Response
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        if ($user->hasBusinessWideScope()) {
            return $this->redirectToRoleWorkspace($user);
        }

        if ($activeBranchContext->current($user) !== null) {
            return $this->redirectToRoleWorkspace($user);
        }

        $activeAssignmentCount = $user->branches()
            ->wherePivot('is_active', true)
            ->count();

        if ($activeAssignmentCount > 1) {
            return to_route('branches.select');
        }

        return Inertia::render('branches/unassigned');
    }

    private function redirectToRoleWorkspace(User $user): RedirectResponse
    {
        $roleNames = $user->roles()->pluck('name');

        $routeName = match (true) {
            $roleNames->contains('super_admin') => 'workspaces.super-admin',
            $roleNames->contains('owner') => 'workspaces.owner',
            $roleNames->contains('cashier'), $roleNames->contains('cashier_kitchen'), $roleNames->contains('kitchen_staff') => $this->branchStaffWorkspace($user, ! $roleNames->contains('cashier') && ! $roleNames->contains('cashier_kitchen')),
            default => null,
        };

        abort_if($routeName === null, 403);

        return to_route($routeName);
    }

    /**
     * Branch staff land on their Role's home workspace, or on the first workspace their effective permissions still
     * allow when Access Control removed it (so a changed baseline never strands an account on a 403).
     */
    private function branchStaffWorkspace(User $user, bool $kitchenFirst): ?string
    {
        $candidates = $kitchenFirst
            ? ['kitchen.access' => 'workspaces.kitchen', 'pos.access' => 'workspaces.cashier']
            : ['pos.access' => 'workspaces.cashier', 'kitchen.access' => 'workspaces.kitchen'];
        $candidates += [
            'transactions.view' => 'workspaces.transaction-history',
            'reports.view' => 'workspaces.reports',
        ];

        foreach ($candidates as $permission => $routeName) {
            if ($user->hasPermission($permission)) {
                return $routeName;
            }
        }

        return null;
    }
}
