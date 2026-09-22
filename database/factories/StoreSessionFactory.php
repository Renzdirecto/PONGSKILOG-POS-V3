<?php

namespace Database\Factories;

use App\Enums\StoreSessionStatus;
use App\Models\Branch;
use App\Models\StoreSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StoreSession>
 */
class StoreSessionFactory extends Factory
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
            'status' => StoreSessionStatus::Open,
            'opened_by_user_id' => User::factory(),
            'opened_at' => now(),
            'opening_cash_amount' => '1000.00',
            'opening_cashless_amount' => '0.00',
            'closing_cash_amount' => null,
            'closing_cashless_amount' => null,
            'expected_cash_amount' => null,
            'expected_cashless_amount' => null,
            'cash_variance' => null,
            'cashless_variance' => null,
            'closing_note' => null,
            'closed_by_user_id' => null,
            'closed_at' => null,
        ];
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => StoreSessionStatus::Closed,
            'closed_by_user_id' => $attributes['closed_by_user_id'] ?? User::factory(),
            'closed_at' => now(),
            'closing_cash_amount' => $attributes['opening_cash_amount'],
            'closing_cashless_amount' => $attributes['opening_cashless_amount'],
            'expected_cash_amount' => $attributes['opening_cash_amount'],
            'expected_cashless_amount' => $attributes['opening_cashless_amount'],
            'cash_variance' => '0.00',
            'cashless_variance' => '0.00',
        ]);
    }
}
