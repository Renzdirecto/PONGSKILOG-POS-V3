<?php

namespace Database\Factories;

use App\Models\LandingTeamMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LandingTeamMember>
 */
class LandingTeamMemberFactory extends Factory
{
    /**
     * A visible member placed after everyone already on the team.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'position' => fn (): int => (int) LandingTeamMember::query()->max('position') + 1,
            'role_label' => 'CREW',
            'role_title' => 'Kitchen Crew',
            'name' => fake()->name(),
            'bio' => fake()->sentence(6),
            'is_visible' => true,
        ];
    }

    public function hidden(): static
    {
        return $this->state(['is_visible' => false]);
    }
}
