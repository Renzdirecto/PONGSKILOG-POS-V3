<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * The public pickup capability of one committed Take Out order (Phase 19.6B). Possessing the raw token is the only
 * permission it grants: read the order's pickup status and opt in to its Ready notification. It is looked up by
 * SHA-256 (`token_hash`); the raw value is stored only encrypted (`token_ciphertext`) so the paired customer screen can
 * render the same QR again during the takeover. Buzz counters are serialized through this row's lock.
 *
 * @property string $id
 * @property string $order_id
 * @property string $branch_id
 * @property string $token_hash
 * @property string $token_ciphertext
 * @property string $channel_key
 * @property Carbon $expires_at
 * @property int $buzz_count
 * @property Carbon|null $last_buzzed_at
 * @property string|null $last_buzz_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Order $order
 * @property PickupPushSubscription|null $pushSubscription
 */
#[Fillable(['order_id', 'branch_id', 'token_hash', 'token_ciphertext', 'channel_key', 'expires_at', 'buzz_count', 'last_buzzed_at', 'last_buzz_key'])]
#[Hidden(['token_hash', 'token_ciphertext', 'channel_key', 'last_buzz_key'])]
class OrderPickupToken extends Model
{
    use HasUuids, MassPrunable;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'token_ciphertext' => 'encrypted',
            'expires_at' => 'datetime',
            'buzz_count' => 'integer',
            'last_buzzed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return HasOne<PickupPushSubscription, $this> */
    public function pushSubscription(): HasOne
    {
        return $this->hasOne(PickupPushSubscription::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->lte(now());
    }

    /**
     * Pickup links are kept a week past their expiry for support questions, then removed with their subscription.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<', now()->subDays(7));
    }
}
