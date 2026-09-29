<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One confirmed pamamalengke run: recommended vs actual quantities, costs and the Ingredient restocks.
 *
 * `store_session_id` is the funding Store Session the buyer chose (Phase 20). Funded by the OPEN session, the money is
 * that session's canonical Store Purchase (`store_session_expense_id`) and counts in its Close Store reconciliation.
 * Funded by a CLOSED session it is a profitability allocation only: no expense row exists and the sealed close result
 * is never changed. The run's amount is always the sum of its item line totals (equal to the expense when one exists).
 *
 * @property string $store_session_id
 * @property string|null $store_session_expense_id
 * @property string $payment_source
 * @property string|null $estimated_total
 */
#[Fillable([
    'branch_id', 'store_session_id', 'operation_plan_id', 'store_session_expense_id', 'payment_source', 'estimated_total',
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

    /** @return BelongsTo<StoreSession, $this> */
    public function fundingSession(): BelongsTo
    {
        return $this->belongsTo(StoreSession::class, 'store_session_id');
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
