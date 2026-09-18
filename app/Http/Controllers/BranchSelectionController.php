<?php

namespace App\Http\Controllers;

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

        if ($user->hasBusinessWideScope()) {
            return to_route('workspace');
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
