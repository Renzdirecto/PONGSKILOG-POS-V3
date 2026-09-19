<?php

namespace App\Http\Controllers;

use App\Enums\BranchStatus;
use App\Enums\StoreSessionStatus;
use App\Models\StoreSession;
use App\Models\User;
use App\Support\ActiveBranchContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CurrentStoreSessionController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, ActiveBranchContext $activeBranchContext): JsonResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);
        abort_unless($user->hasRole('cashier') || $user->hasRole('cashier_kitchen'), 403);

        $branch = $activeBranchContext->current($user);

        abort_unless($branch !== null && $branch->status === BranchStatus::Active, 404);

        $storeSession = StoreSession::query()
            ->select([
                'id',
                'branch_id',
                'opened_by_user_id',
                'opened_at',
                'opening_cash_amount',
                'opening_cashless_amount',
            ])
            ->with('openedBy:id,name')
            ->whereBelongsTo($branch)
            ->where('status', StoreSessionStatus::Open)
            ->sole();

        return response()->json([
            'id' => (string) $storeSession->getKey(),
            'opened_at' => $storeSession->opened_at->toIso8601String(),
            'opening_cash_amount' => $storeSession->opening_cash_amount,
            'opening_cashless_amount' => $storeSession->opening_cashless_amount,
            'opened_by' => [
                'name' => $storeSession->openedBy->name,
            ],
            'branch' => [
                'id' => (string) $branch->getKey(),
                'code' => $branch->code,
                'name' => $branch->name,
            ],
        ])->header('Cache-Control', 'no-store');
    }
}
