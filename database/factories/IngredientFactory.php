<?php

namespace Database\Factories;

use App\Enums\ReplenishmentRule;
use App\Models\Ingredient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ingredient>
 */
class IngredientFactory extends Factory
{
    /**
     * Define the model's default state: bought one piece at a time, topped up to target, cost known.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => ucfirst(fake()->unique()->lexify('Ingredient ????')),
            'icon' => 'box',
            'base_unit' => 'pc',
            'target_quantity' => '10.0000',
            'purchase_unit_name' => 'pc',
            'purchase_unit_size' => '1.0000',
            'purchase_unit_cost' => '10.00',
            'replenishment_rule' => ReplenishmentRule::TopUp,
            'reorder_point' => null,
            'archived_at' => null,
            'created_by_user_id' => User::factory(),
        ];
    }

    /** A pack of several base units, reordered at a threshold (Yakult: 1 pack = 5 pcs, reorder at 2). */
    public function reorderPack(string $size = '5', string $cost = '55.00', string $target = '5', string $at = '2'): static
    {
        return $this->state(fn (): array => [
            'purchase_unit_name' => 'pack',
            'purchase_unit_size' => $size,
            'purchase_unit_cost' => $cost,
            'target_quantity' => $target,
            'replenishment_rule' => ReplenishmentRule::Reorder,
            'reorder_point' => $at,
        ]);
    }

    public function unknownCost(): static
    {
        return $this->state(fn (): array => ['purchase_unit_cost' => null]);
    }

    public function withoutPurchaseUnit(): static
    {
        return $this->state(fn (): array => [
            'purchase_unit_name' => null,
            'purchase_unit_size' => null,
            'purchase_unit_cost' => null,
        ]);
    }
}
