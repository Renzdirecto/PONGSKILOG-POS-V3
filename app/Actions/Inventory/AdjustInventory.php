<?php

namespace App\Actions\Inventory;

use App\Actions\Audit\AuditRecorder;
use App\Enums\InventoryMovementType;
use App\Events\ReportsChanged;
use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class AdjustInventory
{
    public function __construct(private ApplyInventoryMovement $applyMovement, private AuditRecorder $audit) {}

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

        return DB::transaction(function () use ($user, $branch, $product, $quantityDelta, $reason): InventoryMovement {
            $movement = $this->applyMovement->execute(
                branch: $branch,
                product: $product,
                movementType: InventoryMovementType::ManualAdjustment,
                quantityDelta: $quantityDelta,
                reason: $reason,
                actor: $user,
            );
            $this->audit->record(
                branch: $branch,
                actor: $user,
                module: 'inventory',
                action: 'inventory.adjusted',
                auditableType: Product::class,
                auditableId: $product->id,
                metadata: [
                    'movement_id' => $movement->id,
                    'quantity_delta' => $quantityDelta,
                    'reason' => $reason,
                ],
            );
            ReportsChanged::dispatch((string) $branch->id, 'inventory.adjusted');

            return $movement;
        });
    }
}
