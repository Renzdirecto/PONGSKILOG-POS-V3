<?php

namespace Database\Factories;

use App\Enums\BranchStatus;
use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('BR-###-???'),
            'name' => fake()->company(),
            'status' => BranchStatus::Active,
            'address' => fake()->address(),
            'contact' => fake()->phoneNumber(),
            'operating_hours' => null,
        ];
    }
}
