<?php

namespace App\Http\Controllers;

use App\Actions\StoreSessions\RecordStoreSessionInventoryAdjustment;
use App\Http\Requests\StoreSessionInventoryAdjustmentRequest;
use App\Models\BranchInventory;
use App\Models\User;
use App\Support\ActiveBranchContext;
use Illuminate\Http\JsonResponse;

class StoreSessionInventoryAdjustmentController extends Controller
{
    /**
     * Record an inventory-only deduction for the current Store Session.
     */
    public function __invoke(StoreSessionInventoryAdjustmentRequest $request, ActiveBranchContext $context, RecordStoreSessionInventoryAdjustment $record): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $branch = $context->current($user);
        abort_if($branch === null, 403);
        $adjustment = $record->execute($user, $branch, $request->validated());

        return response()->json([
            'adjustment' => [
                'id' => $adjustment->id,
                'reason_code' => $adjustment->reason_code->value,
                'reason_label' => $adjustment->reason_code->label(),
                'product_id' => $adjustment->product_id,
                'product_name' => $adjustment->product->name,
                'quantity' => $adjustment->quantity,
                'note' => $adjustment->note,
                'on_hand' => (int) BranchInventory::query()->where('branch_id', $branch->id)->where('product_id', $adjustment->product_id)->value('on_hand'),
                'movement_id' => $adjustment->inventory_movement_id,
            ],
        ])->header('Cache-Control', 'no-store');
    }
}
