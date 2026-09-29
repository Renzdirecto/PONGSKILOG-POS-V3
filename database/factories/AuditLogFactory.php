<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(), 'user_id' => User::factory(), 'module' => 'transactions',
            'action' => 'committed_order_edited', 'auditable_type' => 'App\\Models\\Order',
            'auditable_id' => fake()->uuid(), 'before' => [], 'after' => [], 'metadata' => [],
        ];
    }
}
