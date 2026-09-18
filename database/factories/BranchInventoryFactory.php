<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BranchInventory> */
class BranchInventoryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'product_id' => Product::factory(),
            'on_hand' => 0,
            'version' => 0,
        ];
    }
}
