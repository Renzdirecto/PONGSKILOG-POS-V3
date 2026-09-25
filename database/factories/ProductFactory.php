<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'name' => fake()->words(3, true),
            'description' => null,
            'default_price' => '125.50',
            'image_path' => null,
            'is_active' => true,
        ];
    }

    /**
     * Adds the Product to these Branches' assortments with default configuration (available, untracked). Without a
     * Branch Product row a Product is not sold at a Branch.
     */
    public function soldAt(Branch ...$branches): static
    {
        return $this->afterCreating(function (Product $product) use ($branches): void {
            foreach ($branches as $branch) {
                BranchProduct::factory()->for($branch)->for($product)->create();
            }
        });
    }
}
