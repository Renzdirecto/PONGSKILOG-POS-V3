<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A customer's opt-in Web Push endpoint for one pickup token (Phase 19.6B). It is never shared with staff
 * `push_subscriptions`: staff messages only ever read that table, and this one only ever receives the Buzz of its own
 * order. The endpoint, key and auth secret are encrypted at rest, hidden from serialization and never logged.
 *
 * @property int $id
 * @property string $order_pickup_token_id
 * @property string $endpoint_hash
 * @property string $endpoint
 * @property string $public_key
 * @property string $auth_token
 * @property string $content_encoding
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property OrderPickupToken $pickupToken
 */
#[Fillable(['order_pickup_token_id', 'endpoint_hash', 'endpoint', 'public_key', 'auth_token', 'content_encoding'])]
#[Hidden(['endpoint_hash', 'endpoint', 'public_key', 'auth_token'])]
class PickupPushSubscription extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'endpoint' => 'encrypted',
            'public_key' => 'encrypted',
            'auth_token' => 'encrypted',
        ];
    }

    /** @return BelongsTo<OrderPickupToken, $this> */
    public function pickupToken(): BelongsTo
    {
        return $this->belongsTo(OrderPickupToken::class, 'order_pickup_token_id');
    }
}
