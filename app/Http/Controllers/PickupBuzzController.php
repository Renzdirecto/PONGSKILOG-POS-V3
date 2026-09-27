<?php

namespace App\Http\Controllers;

use App\Actions\Pickup\BuzzPickupCustomer;
use App\Models\Order;
use App\Models\User;
use App\Support\ActiveBranchContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Buzz Customer on the POS Ready notification (Phase 19.6B); every rule lives in `BuzzPickupCustomer`. */
class PickupBuzzController extends Controller
{
    public function __invoke(Request $request, Order $order, ActiveBranchContext $context, BuzzPickupCustomer $buzz): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $branch = $context->current($user) ?? abort(403);
        abort_unless($order->branch_id === $branch->getKey(), 404);
        $data = $request->validate(['idempotency_key' => ['required', 'uuid']]);

        return response()->json(['buzz' => $buzz->execute($user, $branch, $order, $data['idempotency_key'])]);
    }
}
