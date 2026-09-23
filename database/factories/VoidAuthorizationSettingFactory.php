<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\VoidAuthorizationSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VoidAuthorizationSetting>
 */
class VoidAuthorizationSettingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scope' => 'global',
            'pin_hash' => 'hash',
            'configured_by_user_id' => User::factory(),
            'configured_at' => now(),
        ];
    }
}
