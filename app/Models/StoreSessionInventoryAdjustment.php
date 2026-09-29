<?php

namespace App\Models;

use App\Enums\StockCorrectionDirection;
use App\Enums\StoreInventoryAdjustmentReason;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Store Session Stock Correction (the user-facing name since Phase 20): Product stock moved up or down so the system
 * matches the physical count. `quantity` is the positive size and `direction` its sign; no money is involved.
 *
 * @property StoreInventoryAdjustmentReason $reason_code
 * @property StockCorrectionDirection $direction
 * @property int $quantity
 */
#[Fillable([
    'branch_id', 'store_session_id', 'product_id', 'inventory_movement_id', 'reason_code', 'direction',
    'quantity', 'note', 'created_by_user_id', 'idempotency_key', 'intent_hash',
])]
class StoreSessionInventoryAdjustment extends Model
{
    use HasUuids;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new \LogicException('Stock Corrections are historical records.'));
        static::deleting(fn (): never => throw new \LogicException('Stock Corrections are historical records.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'reason_code' => StoreInventoryAdjustmentReason::class,
            'direction' => StockCorrectionDirection::class,
            'quantity' => 'integer',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<InventoryMovement, $this> */
    public function inventoryMovement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class);
    }
}
