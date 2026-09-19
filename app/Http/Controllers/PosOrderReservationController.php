<?php

namespace App\Http\Controllers;

use App\Actions\Orders\ReservePosOrder;
use App\Enums\OrderType;
use App\Http\Requests\ReservePosOrderRequest;
use App\Models\User;
use App\Support\ActiveBranchContext;
use Illuminate\Http\JsonResponse;

class PosOrderReservationController extends Controller
{
    public function __invoke(ReservePosOrderRequest $request, ActiveBranchContext $context, ReservePosOrder $reserve): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $branch = $context->current($user);
        abort_if($branch === null, 403);
        $order = $reserve->execute($user, $branch, OrderType::from($request->validated('order_type')));

        return response()->json(['order' => [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'reference_number' => $order->reference_number,
            'order_type' => $order->order_type->value,
        ]])->header('Cache-Control', 'no-store');
    }
}
