<?php

namespace Database\Factories;

use App\Enums\InventoryMovementType;
use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InventoryMovement> */
class InventoryMovementFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'product_id' => Product::factory(),
            'movement_type' => InventoryMovementType::ManualAdjustment,
            'quantity_delta' => 1,
            'reason' => 'Opening stock',
            'created_by_user_id' => null,
        ];
    }
}
