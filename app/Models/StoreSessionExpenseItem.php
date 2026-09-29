<?php

namespace App\Models;

use Database\Factories\StoreSessionExpenseItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['store_session_expense_id', 'product_id', 'quantity'])]
class StoreSessionExpenseItem extends Model
{
    /** @use HasFactory<StoreSessionExpenseItemFactory> */
    use HasFactory, HasUuids;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new \LogicException('Store Session expense items are historical records.'));
        static::deleting(fn (): never => throw new \LogicException('Store Session expense items are historical records.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    /** @return BelongsTo<StoreSessionExpense, $this> */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(StoreSessionExpense::class, 'store_session_expense_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
