<?php

namespace App\Http\Controllers;

use App\Enums\BranchStatus;
use App\Enums\StoreSessionStatus;
use App\Models\User;
use App\Support\ActiveBranchContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CashierWorkspaceController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, ActiveBranchContext $activeBranchContext): RedirectResponse|Response
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);
        abort_unless($user->hasRole('cashier') || $user->hasRole('cashier_kitchen'), 403);

        $branch = $activeBranchContext->current($user);

        if ($branch === null) {
            return to_route('workspace');
        }

        $isOpen = $branch->storeSessions()->where('status', StoreSessionStatus::Open)->exists();

        return Inertia::render('workspaces/show', [
            'workspace' => 'Cashier / POS',
            'eyebrow' => 'Branch Operations',
            'description' => 'Branch-scoped cashier and point-of-sale workspace.',
            'store' => [
                'status' => $isOpen ? StoreSessionStatus::Open->value : StoreSessionStatus::Closed->value,
                'branchStatus' => $branch->status->value,
                'canOpen' => ! $isOpen && $branch->status === BranchStatus::Active
                    && $user->hasPermission('store.open_close')
                    && $user->branches()->whereKey($branch->getKey())->wherePivot('is_active', true)->exists(),
            ],
        ]);
    }
}
