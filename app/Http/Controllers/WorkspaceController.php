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
            return $this->redirectToRoleWorkspace($user, $activeBranchContext);
        }

        if ($activeBranchContext->current($user) !== null) {
            return $this->redirectToRoleWorkspace($user, $activeBranchContext);
        }

        $activeAssignmentCount = $user->branches()
            ->wherePivot('is_active', true)
            ->count();

        if ($activeAssignmentCount > 1) {
            return to_route('branches.select');
        }

        return Inertia::render('branches/unassigned');
    }

    private function redirectToRoleWorkspace(User $user, ActiveBranchContext $activeBranchContext): RedirectResponse
    {
        $routeName = match (true) {
            $user->hasRole('super_admin') => 'workspaces.super-admin',
            $user->hasBusinessWideScope() => $this->firstAllowed($user, [
                'reports.view' => 'workspaces.owner',
                'transactions.view' => 'workspaces.transactions',
                'products.manage' => 'products.index',
                'inventory.manage' => 'inventory.index',
                'operations.manage' => 'operations.plans',
                'staff.manage' => 'staff.index',
                'settings.manage' => 'branches.index',
            ]) ?? $this->businessWideOperationsWorkspace($user, $activeBranchContext),
            $user->roles()->exists() => $this->branchStaffWorkspace($user, ! $user->hasCashierOperationsRole()),
            default => null,
        };

        abort_if($routeName === null, 403);

        return to_route($routeName);
    }

    /**
     * Branch staff (System or Branch Custom Role) land on their Role's home workspace, or on the first workspace their
     * effective permissions still allow when Access Control removed it (so a changed baseline never strands an account
     * on a 403).
     */
    private function branchStaffWorkspace(User $user, bool $kitchenFirst): ?string
    {
        $candidates = $kitchenFirst
            ? ['kitchen.access' => 'workspaces.kitchen', 'pos.access' => 'workspaces.cashier']
            : ['pos.access' => 'workspaces.cashier', 'kitchen.access' => 'workspaces.kitchen'];

        return $this->firstAllowed($user, $candidates + [
            'transactions.view' => 'workspaces.transaction-history',
            'reports.view' => 'workspaces.reports',
            'customer_display.launch' => 'workspaces.customer-display',
        ]);
    }

    /**
     * A business-wide account without a management page (for example a Custom Role granted only POS or Kitchen) runs
     * Branch operations at one concrete Branch: it chooses a Branch first, then lands on its operational workspace.
     */
    private function businessWideOperationsWorkspace(User $user, ActiveBranchContext $activeBranchContext): ?string
    {
        if (! $user->operatesEveryBranch()) {
            return null;
        }
        $workspace = $this->branchStaffWorkspace($user, ! $user->hasPermission('pos.access'));

        return $workspace !== null && $activeBranchContext->current($user) === null ? 'branches.select' : $workspace;
    }

    /**
     * The first destination whose permission the account holds. Owner and business-wide Custom Roles use the
     * management pages; Branch staff use their operational workspaces.
     *
     * @param  array<string, string>  $candidates  permission => route name, in preference order
     */
    private function firstAllowed(User $user, array $candidates): ?string
    {
        foreach ($candidates as $permission => $routeName) {
            if ($user->hasPermission($permission)) {
                return $routeName;
            }
        }

        return null;
    }
}
