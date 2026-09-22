<?php

namespace Database\Factories;

use App\Enums\KitchenStatus;
use App\Models\KitchenTicket;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<KitchenTicket> */
class KitchenTicketFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'branch_id' => fn (array $attributes) => Order::query()->whereKey($attributes['order_id'])->sole()->branch_id,
            'status' => KitchenStatus::Kitchen,
        ];
    }
}
