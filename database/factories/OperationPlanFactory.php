<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\OperationPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OperationPlan>
 */
class OperationPlanFactory extends Factory
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
            'name' => ucfirst(fake()->unique()->word()),
            'description' => null,
            'icon' => 'box',
            'archived_at' => null,
            'created_by_user_id' => User::factory(),
        ];
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['archived_at' => now()]);
    }
}
