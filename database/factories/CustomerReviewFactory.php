<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\CustomerReview;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CustomerReview>
 */
class CustomerReviewFactory extends Factory
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
            'submission_key' => Str::uuid()->toString(),
            'submission_digest' => hash('sha256', Str::random(64)),
            'rating' => 5,
            'preferred_name' => fake()->firstName(),
            'description' => fake()->sentence(),
            'visibility' => 'public',
            'status' => 'pending',
            'public_consent' => true,
            'consented_at' => now(),
            'notice_version' => CustomerReview::NOTICE_VERSION,
        ];
    }
}
