<?php

namespace App\Actions\Inventory;

use App\Enums\InventoryMovementType;
use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class AdjustInventory
{
    public function __construct(private ApplyInventoryMovement $applyMovement) {}

    public function execute(User $user, Branch $branch, Product $product, int $quantityDelta, string $reason): InventoryMovement
    {
        Gate::forUser($user)->authorize('inventory.manage');

        $reason = trim($reason);

        Validator::make([
            'quantity_delta' => $quantityDelta,
            'reason' => $reason,
        ], [
            'quantity_delta' => ['required', 'integer', 'not_in:0'],
            'reason' => ['required', 'string', 'max:1000'],
        ])->validate();

        return $this->applyMovement->execute(
            branch: $branch,
            product: $product,
            movementType: InventoryMovementType::ManualAdjustment,
            quantityDelta: $quantityDelta,
            reason: $reason,
            actor: $user,
        );
    }
}
