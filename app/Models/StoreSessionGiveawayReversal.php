<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The one compensating correction of a mistaken Giveaway: it restores exactly the recorded stock effect, once. */
#[Fillable([
    'giveaway_id', 'branch_id', 'store_session_id', 'inventory_movement_id', 'reason', 'created_by_user_id',
    'idempotency_key', 'intent_hash',
])]
class StoreSessionGiveawayReversal extends Model
{
    use HasUuids;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new \LogicException('Giveaway reversals are historical records.'));
        static::deleting(fn (): never => throw new \LogicException('Giveaway reversals are historical records.'));
    }

    /** @return BelongsTo<StoreSessionGiveaway, $this> */
    public function giveaway(): BelongsTo
    {
        return $this->belongsTo(StoreSessionGiveaway::class, 'giveaway_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
