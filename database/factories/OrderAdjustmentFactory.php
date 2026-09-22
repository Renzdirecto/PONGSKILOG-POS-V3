<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderAdjustment;
use App\Models\StoreSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OrderAdjustment>
 */
class OrderAdjustmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'branch_id' => fn (array $attributes) => Order::query()->whereKey($attributes['order_id'])->value('branch_id'),
            'store_session_id' => fn (array $attributes) => StoreSession::factory()->create(['branch_id' => $attributes['branch_id']])->id,
            'type' => 'lower_total_correction', 'amount' => '10.00', 'reason' => null,
            'created_by_user_id' => User::factory(), 'idempotency_key' => Str::uuid(),
        ];
    }
}
