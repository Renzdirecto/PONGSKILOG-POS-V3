<?php

namespace App\Http\Controllers;

use App\Actions\Orders\EditCommittedOrder;
use App\Http\Requests\EditCommittedOrderRequest;
use App\Models\Order;
use App\Models\User;
use App\Support\ActiveBranchContext;
use App\Support\TransactionProjection;
use Illuminate\Http\JsonResponse;

class CommittedOrderEditController extends Controller
{
    public function __invoke(EditCommittedOrderRequest $request, Order $order, ActiveBranchContext $context, EditCommittedOrder $edit, TransactionProjection $projection): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $branch = $context->current($user);
        abort_if($branch === null, 403);
        $order = $edit->execute($user, $branch, $order, $request->validated());

        return response()->json(['transaction' => $projection->detail($order->fresh(), true)])->header('Cache-Control', 'no-store');
    }
}
