<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BranchProduct>
 */
class BranchProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'product_id' => Product::factory(),
            'price_override' => null,
            'is_available' => true,
            'tracks_inventory' => false,
            'low_stock_threshold' => null,
            'no_recipe_needed' => false,
        ];
    }
}
