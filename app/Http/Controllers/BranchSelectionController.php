<?php

namespace App\Http\Controllers;

use App\Enums\BranchStatus;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BranchSelectionController extends Controller
{
    public function __invoke(Request $request): RedirectResponse|Response
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        /** Business-wide operators (Super Admin, business-wide Custom Roles) pick any active Branch for Branch operations. */
        if ($user->hasBusinessWideScope()) {
            if (! $user->operatesEveryBranch()) {
                return to_route('workspace');
            }

            return Inertia::render('branches/select', [
                'branches' => Branch::query()
                    ->where('status', BranchStatus::Active)
                    ->orderBy('name')
                    ->orderBy('code')
                    ->get(['id', 'name', 'code']),
            ]);
        }

        $activeAssignmentCount = $user->branches()
            ->wherePivot('is_active', true)
            ->count();

        if ($activeAssignmentCount < 2) {
            return to_route('workspace');
        }

        return Inertia::render('branches/select');
    }
}
