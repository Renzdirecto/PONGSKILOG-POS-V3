<?php

namespace App\Models;

use Database\Factories\StoreSessionExpenseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'branch_id', 'store_session_id', 'description', 'amount', 'payment_source', 'note',
    'receipt_disk', 'receipt_image_path', 'receipt_original_name', 'receipt_mime_type',
    'receipt_size_bytes', 'receipt_sha256', 'created_by_user_id', 'idempotency_key', 'intent_hash',
])]
class StoreSessionExpense extends Model
{
    /** @use HasFactory<StoreSessionExpenseFactory> */
    use HasFactory, HasUuids;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new \LogicException('Store Session expenses are historical records.'));
        static::deleting(fn (): never => throw new \LogicException('Store Session expenses are historical records.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'receipt_size_bytes' => 'integer'];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<StoreSession, $this> */
    public function storeSession(): BelongsTo
    {
        return $this->belongsTo(StoreSession::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return HasOne<StoreSessionExpenseItem, $this> */
    public function item(): HasOne
    {
        return $this->hasOne(StoreSessionExpenseItem::class);
    }

    /** @return HasMany<InventoryMovement, $this> */
    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }
}
