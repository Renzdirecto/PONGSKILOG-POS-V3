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
            $roleNames->contains('cashier'), $roleNames->contains('cashier_kitchen') => 'workspaces.cashier',
            $roleNames->contains('kitchen_staff') => 'workspaces.kitchen',
            default => null,
        };

        abort_if($routeName === null, 403);

        return to_route($routeName);
    }
}
