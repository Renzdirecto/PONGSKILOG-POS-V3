<?php

namespace Database\Factories;

use App\Models\ModifierOption;
use App\Models\OrderItem;
use App\Models\OrderItemModifier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItemModifier>
 */
class OrderItemModifierFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_item_id' => OrderItem::factory(), 'modifier_option_id' => ModifierOption::factory(), 'group_name_snapshot' => 'Extras', 'option_name_snapshot' => 'Egg', 'price_delta_snapshot' => '20.00', 'quantity' => 1,
        ];
    }
}
