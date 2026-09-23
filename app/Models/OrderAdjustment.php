<?php

namespace App\Models;

use Database\Factories\OrderAdjustmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $amount
 * @property string|null $cash_amount
 * @property string|null $cashless_amount
 */
#[Fillable(['branch_id', 'store_session_id', 'order_id', 'type', 'amount', 'cash_amount', 'cashless_amount', 'reason', 'created_by_user_id', 'idempotency_key'])]
class OrderAdjustment extends Model
{
    /** @use HasFactory<OrderAdjustmentFactory> */
    use HasFactory, HasUuids;

    protected static function booted(): void
    {
        /** Corrections are append-only; only a missing historical Cash/Cashless allocation may be recorded once. */
        static::updating(function (OrderAdjustment $adjustment): void {
            $allocationOnly = array_diff(array_keys($adjustment->getDirty()), ['cash_amount', 'cashless_amount', 'updated_at']) === [];
            if (! $allocationOnly
                || $adjustment->getRawOriginal('cash_amount') !== null
                || $adjustment->getRawOriginal('cashless_amount') !== null
                || $adjustment->cash_amount === null
                || $adjustment->cashless_amount === null) {
                throw new \LogicException('Payment corrections are historical records.');
            }
        });
        static::deleting(fn (): never => throw new \LogicException('Payment corrections are historical records.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'cash_amount' => 'decimal:2', 'cashless_amount' => 'decimal:2'];
    }

    public function isAllocated(): bool
    {
        return $this->cash_amount !== null && $this->cashless_amount !== null;
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
