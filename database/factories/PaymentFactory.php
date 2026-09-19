<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\Payment;
use App\Models\StoreSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Payment> */
class PaymentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'branch_id' => fn (array $attributes) => Order::query()->whereKey($attributes['order_id'])->sole()->branch_id,
            'store_session_id' => fn (array $attributes) => StoreSession::factory()->create(['branch_id' => $attributes['branch_id']])->id,
            'method' => PaymentMethod::Cash,
            'amount' => '100.00', 'amount_received' => '100.00', 'change_amount' => '0.00',
            'created_by_user_id' => User::factory(), 'idempotency_key' => Str::uuid().':cash', 'paid_at' => now(),
        ];
    }
}
