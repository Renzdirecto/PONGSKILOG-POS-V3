<?php

namespace App\Actions\Inventory;

use App\Enums\InventoryMovementType;
use App\Events\CustomerCatalogChanged;
use App\Events\InventoryChanged;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\BranchProduct;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\User;
use App\Support\InventoryState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ApplyInventoryMovement
{
    public function __construct(private InventoryState $inventoryState) {}

    /** Internal primitive: callers authorize their workflow before applying stock changes. */
    public function execute(
        Branch $branch,
        Product $product,
        InventoryMovementType $movementType,
        int $quantityDelta,
        ?string $reason = null,
        ?User $actor = null,
        ?string $orderId = null,
        ?string $storeSessionExpenseId = null,
        ?string $stockTransferId = null,
    ): InventoryMovement {
        if ($quantityDelta === 0) {
            throw ValidationException::withMessages(['quantity_delta' => 'The inventory quantity delta must not be zero.']);
        }

        Validator::make([
            'order_id' => $orderId,
            'store_session_expense_id' => $storeSessionExpenseId,
            'stock_transfer_id' => $stockTransferId,
        ], [
            'order_id' => ['nullable', 'uuid'],
            'store_session_expense_id' => ['nullable', 'uuid'],
            'stock_transfer_id' => ['nullable', 'uuid'],
        ])->validate();

        return DB::transaction(function () use ($branch, $product, $movementType, $quantityDelta, $reason, $actor, $orderId, $storeSessionExpenseId, $stockTransferId): InventoryMovement {
            $branch = Branch::query()->whereKey($branch->getKey())->firstOrFail();
            $product = Product::query()->whereKey($product->getKey())->firstOrFail();
            $actor = $actor === null ? null : User::query()->whereKey($actor->getKey())->firstOrFail();

            $branchProduct = BranchProduct::query()
                ->where('branch_id', $branch->id)
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->first();

            if ($branchProduct === null || ! $branchProduct->tracks_inventory) {
                throw ValidationException::withMessages(['product_id' => 'Inventory is not tracked for this product at this branch.']);
            }

            /** The unique pair and PostgreSQL ON CONFLICT DO NOTHING protect first-row creation. */
            BranchInventory::query()->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'branch_id' => $branch->id,
                'product_id' => $product->id,
                'on_hand' => 0,
                'version' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $balance = BranchInventory::query()
                ->where('branch_id', $branch->id)
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($quantityDelta < -$balance->on_hand) {
                throw ValidationException::withMessages(['quantity_delta' => 'Insufficient stock for this inventory movement.']);
            }

            if (($quantityDelta > 0 && $balance->on_hand > PHP_INT_MAX - $quantityDelta)
                || $balance->version === PHP_INT_MAX) {
                throw ValidationException::withMessages(['quantity_delta' => 'The inventory balance or version would exceed the supported integer range.']);
            }

            $balance->update([
                'on_hand' => $balance->on_hand + $quantityDelta,
                'version' => $balance->version + 1,
            ]);

            $movement = InventoryMovement::query()->create([
                'branch_id' => $branch->id,
                'product_id' => $product->id,
                'movement_type' => $movementType,
                'quantity_delta' => $quantityDelta,
                'reason' => $reason,
                'created_by_user_id' => $actor?->id,
                'order_id' => $orderId,
                'store_session_expense_id' => $storeSessionExpenseId,
                'stock_transfer_id' => $stockTransferId,
            ]);

            $state = $this->inventoryState->resolve($branchProduct, $balance);
            InventoryChanged::dispatch(
                $branch->id,
                $product->id,
                $balance->on_hand,
                $state['low_stock_threshold'],
                $state['status'],
                $balance->version,
            );

            CustomerCatalogChanged::dispatch($branch->id);

            return $movement;
        });
    }
}
