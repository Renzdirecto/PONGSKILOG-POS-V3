<?php

namespace App\Http\Controllers;

use App\Actions\StoreSessions\CloseStoreSession;
use App\Enums\BranchStatus;
use App\Enums\StoreSessionStatus;
use App\Http\Requests\CloseStoreSessionRequest;
use App\Models\StoreSession;
use App\Models\User;
use App\Support\ActiveBranchContext;
use App\Support\PosAccess;
use App\Support\StoreSessionReconciliation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreSessionCloseController extends Controller
{
    /**
     * Read-only pre-close checks and, when no blocker remains, the server-derived reconciliation.
     */
    public function show(Request $request, ActiveBranchContext $context, PosAccess $access, StoreSessionReconciliation $reconciliation): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $branch = $context->current($user);
        abort_unless($branch !== null && $branch->status === BranchStatus::Active, 404);
        $access->authorize($user, $branch);

        $session = StoreSession::query()
            ->with('openedBy:id,name')
            ->whereBelongsTo($branch)
            ->where('status', StoreSessionStatus::Open)
            ->first();
        abort_if($session === null, 404, 'The Store is already closed.');

        return response()->json([
            'store_session' => [
                'id' => $session->id,
                'opened_at' => $session->opened_at->toIso8601String(),
                'opened_by' => ['name' => $session->openedBy->name],
                'opening_cash_amount' => $session->opening_cash_amount,
                'opening_cashless_amount' => $session->opening_cashless_amount,
                'branch' => ['id' => $branch->id, 'code' => $branch->code, 'name' => $branch->name],
            ],
            ...$reconciliation->preview($branch, $session),
            'generated_at' => now()->toIso8601String(),
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * Close the current Store Session after the server recomputes every blocker and balance.
     */
    public function store(CloseStoreSessionRequest $request, ActiveBranchContext $context, CloseStoreSession $close): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $branch = $context->current($user);
        abort_if($branch === null, 403);

        $result = $close->execute($user, $branch, $request->validated());
        $session = $result['session']->load('closedBy:id,name');

        return response()->json([
            'replayed' => $result['replayed'],
            'store_session' => [
                'id' => $session->id,
                'status' => $session->status->value,
                'branch' => ['code' => $branch->code, 'name' => $branch->name],
                'opened_at' => $session->opened_at->toIso8601String(),
                'closed_at' => $session->closed_at?->toIso8601String(),
                'closed_by' => ['name' => $session->closedBy?->name],
                'opening_cash_amount' => $session->opening_cash_amount,
                'opening_cashless_amount' => $session->opening_cashless_amount,
                'expected_cash_amount' => $session->expected_cash_amount,
                'expected_cashless_amount' => $session->expected_cashless_amount,
                'closing_cash_amount' => $session->closing_cash_amount,
                'closing_cashless_amount' => $session->closing_cashless_amount,
                'cash_variance' => $session->cash_variance,
                'cashless_variance' => $session->cashless_variance,
                'closing_note' => $session->closing_note,
                'qr_archived_count' => $result['qr_archived_count'],
            ],
        ])->header('Cache-Control', 'no-store');
    }
}
