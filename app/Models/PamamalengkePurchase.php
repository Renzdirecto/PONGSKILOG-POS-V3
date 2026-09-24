<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Metadata of one confirmed pamamalengke run. The money itself is the linked canonical Store Session expense;
 * this record adds recommended vs actual quantities and the Ingredient restocks.
 *
 * @property string|null $estimated_total
 */
#[Fillable([
    'branch_id', 'store_session_id', 'operation_plan_id', 'store_session_expense_id', 'estimated_total',
    'estimate_complete', 'note', 'created_by_user_id', 'idempotency_key', 'intent_hash',
])]
class PamamalengkePurchase extends Model
{
    use HasUuids;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new \LogicException('Pamamalengke purchases are historical records.'));
        static::deleting(fn (): never => throw new \LogicException('Pamamalengke purchases are historical records.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['estimated_total' => 'decimal:2', 'estimate_complete' => 'boolean'];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<OperationPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(OperationPlan::class, 'operation_plan_id');
    }

    /** @return BelongsTo<StoreSessionExpense, $this> */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(StoreSessionExpense::class, 'store_session_expense_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return HasMany<PamamalengkePurchaseItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PamamalengkePurchaseItem::class);
    }
}
