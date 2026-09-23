<?php

namespace App\Http\Controllers;

use App\Enums\CommercialStatus;
use App\Enums\StoreSessionStatus;
use App\Http\Requests\TransactionHistoryRequest;
use App\Models\Order;
use App\Models\User;
use App\Support\ActiveBranchContext;
use App\Support\BranchCatalog;
use App\Support\PosAccess;
use App\Support\PosReceipt;
use App\Support\TransactionHistory;
use App\Support\TransactionProjection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TransactionHistoryController extends Controller
{
    public function index(TransactionHistoryRequest $request, ActiveBranchContext $context, PosAccess $access, TransactionHistory $history, BranchCatalog $catalog): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $branch = $context->current($user);
        abort_if($branch === null, 403);
        $access->authorize($user, $branch);

        return Inertia::render('workspaces/transaction-history', [
            ...$history->for($branch, $request->validated()),
            'filters' => $request->safe()->except('page'),
            'catalog' => fn () => $catalog->browse($branch, true),
            'tables' => fn () => $branch->tables()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(Request $request, Order $order, ActiveBranchContext $context, PosAccess $access, TransactionProjection $projection, PosReceipt $receipt): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $branch = $context->current($user);
        abort_if($branch === null, 403);
        $access->authorize($user, $branch);
        $order = Order::query()
            ->where('branch_id', $branch->id)
            ->whereNotNull('committed_at')
            ->where('commercial_status', '!=', CommercialStatus::Voided)
            ->with('items.modifiers', 'payments.createdBy', 'payments.invoiceProof', 'adjustments.createdBy', 'branchTable')
            ->findOrFail($order->id);
        $canMutate = $order->commercial_status->value === 'active'
            && $branch->storeSessions()->where('status', StoreSessionStatus::Open)->whereKey($order->store_session_id)->exists();

        return response()->json([
            'transaction' => [
                ...$projection->detail($order, $canMutate),
                'receipt' => $receipt->summary($order),
            ],
        ])->header('Cache-Control', 'no-store');
    }
}
