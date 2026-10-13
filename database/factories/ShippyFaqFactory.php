<?php

namespace Database\Factories;

use App\Models\ShippyFaq;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShippyFaq>
 */
class ShippyFaqFactory extends Factory
{
    /**
     * A switched-on FAQ with no other wordings.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'question' => fake()->sentence(5).'?',
            'answer' => fake()->sentence(8),
            'category' => null,
            'alternatives' => [],
            'is_enabled' => true,
        ];
    }

    public function disabled(): static
    {
        return $this->state(['is_enabled' => false]);
    }
}
