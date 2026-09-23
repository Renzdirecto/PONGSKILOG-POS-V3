<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\StoreSessionExpense;
use App\Models\StoreSessionExpenseItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StoreSessionExpenseItem>
 */
class StoreSessionExpenseItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_session_expense_id' => StoreSessionExpense::factory(),
            'product_id' => Product::factory(),
            'quantity' => fake()->numberBetween(1, 50),
        ];
    }
}
