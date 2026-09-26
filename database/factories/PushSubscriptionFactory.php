<?php

namespace Database\Factories;

use App\Models\PushSubscription;
use App\Models\User;
use App\Support\PushDevice;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PushSubscription>
 */
class PushSubscriptionFactory extends Factory
{
    /**
     * The receiver key and auth secret of the RFC 8291 (Message Encryption for Web Push) Appendix A example: a valid
     * uncompressed P-256 point and 16-byte secret, public test data rather than any real browser's keys.
     */
    public const BROWSER_PUBLIC_KEY = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';

    public const BROWSER_AUTH_SECRET = 'BTBZMqHH6r4Tts7J_aSIgg';

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/'.Str::random(48),
            'endpoint_hash' => fn (array $attributes): string => PushSubscription::hashEndpoint($attributes['endpoint']),
            'device_hash' => PushDevice::hash(PushDevice::newId()),
            'public_key' => self::BROWSER_PUBLIC_KEY,
            'auth_token' => self::BROWSER_AUTH_SECRET,
            'content_encoding' => 'aes128gcm',
        ];
    }
}
