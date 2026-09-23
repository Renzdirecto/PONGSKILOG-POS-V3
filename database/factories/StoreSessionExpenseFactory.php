<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\StoreSession;
use App\Models\StoreSessionExpense;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StoreSessionExpense>
 */
class StoreSessionExpenseFactory extends Factory
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
            'store_session_id' => function (array $attributes): Factory {
                $branchId = $attributes['branch_id'] ?? null;
                if (! is_string($branchId)) {
                    throw new \LogicException('The expense factory requires a persisted Branch identifier.');
                }

                return StoreSession::factory()->for(Branch::query()->whereKey($branchId)->sole());
            },
            'description' => fake()->words(3, true),
            'amount' => '100.00',
            'payment_source' => 'cash',
            'note' => null,
            'receipt_disk' => null,
            'receipt_image_path' => null,
            'receipt_original_name' => null,
            'receipt_mime_type' => null,
            'receipt_size_bytes' => null,
            'receipt_sha256' => null,
            'created_by_user_id' => User::factory(),
            'idempotency_key' => (string) Str::uuid(),
            'intent_hash' => hash('sha256', (string) Str::uuid()),
        ];
    }
}
