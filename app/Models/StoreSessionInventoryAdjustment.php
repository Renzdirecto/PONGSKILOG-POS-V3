<?php

namespace App\Models;

use App\Enums\StoreInventoryAdjustmentReason;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property StoreInventoryAdjustmentReason $reason_code
 * @property int $quantity
 */
#[Fillable([
    'branch_id', 'store_session_id', 'product_id', 'inventory_movement_id', 'reason_code',
    'quantity', 'note', 'created_by_user_id', 'idempotency_key', 'intent_hash',
])]
class StoreSessionInventoryAdjustment extends Model
{
    use HasUuids;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new \LogicException('Store Session inventory adjustments are historical records.'));
        static::deleting(fn (): never => throw new \LogicException('Store Session inventory adjustments are historical records.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['reason_code' => StoreInventoryAdjustmentReason::class, 'quantity' => 'integer'];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<InventoryMovement, $this> */
    public function inventoryMovement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class);
    }
}
