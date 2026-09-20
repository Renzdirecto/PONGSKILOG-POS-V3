<?php

namespace App\Http\Controllers;

use App\Actions\Orders\SettlePayLaterOrder;
use App\Http\Requests\SettlePayLaterOrderRequest;
use App\Models\Order;
use App\Models\User;
use App\Support\ActiveBranchContext;
use App\Support\PosReceipt;
use Illuminate\Http\JsonResponse;

class PosPayLaterSettlementController extends Controller
{
    public function store(
        SettlePayLaterOrderRequest $request,
        Order $order,
        ActiveBranchContext $context,
        SettlePayLaterOrder $settle,
        PosReceipt $receipt,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $branch = $context->current($user);
        abort_if($branch === null, 403);
        $order = $settle->execute($user, $branch, $order, $request->validated());

        return response()->json(['receipt' => $receipt->summary($order)])->header('Cache-Control', 'no-store');
    }
}
