<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\BranchTable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BranchTable>
 */
class BranchTableFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(), 'name' => fake()->unique()->bothify('Table ##??'), 'sort_order' => 0, 'is_active' => true,
        ];
    }
}
