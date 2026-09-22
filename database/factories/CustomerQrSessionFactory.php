<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\CustomerQrSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CustomerQrSession> */
class CustomerQrSessionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['branch_id' => Branch::factory(), 'token_hash' => hash('sha256', bin2hex(random_bytes(32))), 'expires_at' => now()->addDays(7)];
    }
}
