<?php

namespace Database\Factories;

use App\Enums\ModifierSelectionType;
use App\Models\ModifierGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ModifierGroup>
 */
class ModifierGroupFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'semantic_role' => null,
            'selection_type' => ModifierSelectionType::Single,
            'min_select' => 0,
            'max_select' => 1,
            'is_active' => true,
        ];
    }
}
