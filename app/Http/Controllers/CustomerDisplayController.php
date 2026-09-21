<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\ActiveBranchContext;
use App\Support\KitchenBoard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CustomerDisplayController extends Controller
{
    public function __invoke(
        Request $request,
        ActiveBranchContext $activeBranchContext,
        KitchenBoard $kitchenBoard,
    ): RedirectResponse|Response {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $branch = $activeBranchContext->current($user);

        if ($branch === null) {
            return to_route('workspace');
        }

        return Inertia::render('workspaces/customer-display', [
            'branchId' => (string) $branch->getKey(),
            'branchName' => $branch->name,
            'display' => fn (): array => $kitchenBoard->customerDisplay($branch),
        ]);
    }
}
