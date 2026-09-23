<?php

namespace App\Http\Controllers;

use App\Actions\Orders\AllocateOrderAdjustment;
use App\Http\Requests\AllocateOrderAdjustmentRequest;
use App\Models\OrderAdjustment;
use App\Models\User;
use App\Support\ActiveBranchContext;
use Illuminate\Http\JsonResponse;

class OrderAdjustmentAllocationController extends Controller
{
    /**
     * Record the Cash/Cashless source of a historical mixed-method payment correction.
     */
    public function __invoke(AllocateOrderAdjustmentRequest $request, OrderAdjustment $adjustment, ActiveBranchContext $context, AllocateOrderAdjustment $allocate): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $branch = $context->current($user);
        abort_if($branch === null, 403);
        $adjustment = $allocate->execute($user, $branch, $adjustment, $request->validated());

        return response()->json([
            'adjustment' => [
                'id' => $adjustment->id,
                'amount' => $adjustment->amount,
                'cash_amount' => $adjustment->cash_amount,
                'cashless_amount' => $adjustment->cashless_amount,
            ],
        ])->header('Cache-Control', 'no-store');
    }
}
