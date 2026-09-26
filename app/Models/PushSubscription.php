<?php

namespace App\Models;

use Database\Factories\PushSubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A browser's Web Push subscription, owned by the account that last enabled notifications in that browser. The
 * endpoint, key and auth secret are delivery material only: encrypted at rest, hidden from serialization, never
 * returned to the UI, logged or audited.
 *
 * @property int $id
 * @property int $user_id
 * @property string $endpoint_hash
 * @property string|null $device_hash
 * @property string $endpoint
 * @property string $public_key
 * @property string $auth_token
 * @property string $content_encoding
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'endpoint_hash', 'device_hash', 'endpoint', 'public_key', 'auth_token', 'content_encoding'])]
#[Hidden(['endpoint_hash', 'device_hash', 'endpoint', 'public_key', 'auth_token'])]
class PushSubscription extends Model
{
    /** @use HasFactory<PushSubscriptionFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'endpoint' => 'encrypted',
            'public_key' => 'encrypted',
            'auth_token' => 'encrypted',
        ];
    }

    /** The deterministic unique key of a browser endpoint (the endpoint itself is stored encrypted). */
    public static function hashEndpoint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
