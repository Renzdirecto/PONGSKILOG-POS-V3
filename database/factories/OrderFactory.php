<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(), 'order_number' => fake()->unique()->bothify('######-????????'), 'source' => 'pos', 'order_type' => 'take_out', 'customer_label' => 'Counter order', 'commercial_status' => 'draft', 'payment_status' => 'unpaid', 'kitchen_status' => 'not_sent', 'subtotal' => '0.00', 'total' => '0.00',
        ];
    }
}
