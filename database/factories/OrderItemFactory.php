<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(), 'product_id' => Product::factory(), 'product_name_snapshot' => 'Tapsilog', 'unit_price' => '95.00', 'quantity' => 1, 'line_total' => '95.00',
        ];
    }
}
