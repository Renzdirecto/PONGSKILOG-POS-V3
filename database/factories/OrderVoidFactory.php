<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderVoid;
use App\Models\StoreSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderVoid>
 */
class OrderVoidFactory extends Factory
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
            'store_session_id' => StoreSession::factory(),
            'order_id' => Order::factory(),
            'initiated_by_user_id' => User::factory(),
            'authorized_by_user_id' => User::factory(),
            'reason_code' => 'wrong_item',
            'reason_label' => 'Wrong item rung up',
            'reason_text' => null,
            'authorization_method' => 'password_reauth',
            'idempotency_key' => fake()->uuid(),
        ];
    }
}
