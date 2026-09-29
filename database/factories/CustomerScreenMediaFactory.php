<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\CustomerScreenMedia;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<CustomerScreenMedia> */
class CustomerScreenMediaFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'media_type' => 'image',
            'label' => fake()->words(2, true),
            'path' => fn (array $attributes) => 'customer-screen/'.$attributes['branch_id'].'/'.Str::uuid().'/display.webp',
            'mime_type' => 'image/webp',
            'size_bytes' => 2048,
            'duration_seconds' => 8,
            'sort_order' => 0,
            'is_active' => true,
        ];
    }

    public function video(): static
    {
        return $this->state([
            'media_type' => 'video',
            'path' => fn (array $attributes) => 'customer-screen/'.$attributes['branch_id'].'/'.Str::uuid().'/video.mp4',
            'mime_type' => 'video/mp4',
            'duration_seconds' => 15,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
