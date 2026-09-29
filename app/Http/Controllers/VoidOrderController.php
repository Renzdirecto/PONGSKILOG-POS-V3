<?php

namespace App\Http\Controllers;

use App\Actions\Orders\VoidOrder;
use App\Http\Requests\VoidOrderRequest;
use App\Models\Order;
use App\Models\User;
use App\Support\ActiveBranchContext;
use App\Support\TransactionProjection;
use Illuminate\Http\JsonResponse;

class VoidOrderController extends Controller
{
    public function __invoke(
        VoidOrderRequest $request,
        Order $order,
        ActiveBranchContext $activeBranchContext,
        VoidOrder $voidOrder,
        TransactionProjection $projection,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $branch = $activeBranchContext->current($user);
        abort_if($branch === null, 403);
        abort_unless($order->branch_id === $branch->id, 404);

        $order = $voidOrder->execute($user, $branch, $order, $request->validated());
        $order->load('items.modifiers', 'payments.createdBy', 'payments.invoiceProof', 'adjustments.createdBy', 'branchTable', 'voidRecord.initiatedBy', 'voidRecord.authorizedBy');

        return response()->json([
            'transaction' => $projection->detail($order, false),
        ])->header('Cache-Control', 'no-store');
    }
}
