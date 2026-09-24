<?php

namespace App\Models;

use App\Enums\IngredientMovementType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only Ingredient ledger row. Never updated or deleted: corrections are new movements.
 *
 * @property IngredientMovementType $movement_type
 * @property string $quantity_delta
 * @property string $balance_after
 */
#[Fillable([
    'branch_id', 'ingredient_id', 'movement_type', 'quantity_delta', 'balance_after', 'estimated_cost_cents',
    'order_id', 'order_recipe_snapshot_id', 'operation_plan_id', 'pamamalengke_purchase_id', 'store_session_expense_id',
    'reason_code', 'reason', 'created_by_user_id', 'idempotency_key',
])]
class IngredientMovement extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new \LogicException('Ingredient movements are append-only.'));
        static::deleting(fn (): never => throw new \LogicException('Ingredient movements are append-only.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'movement_type' => IngredientMovementType::class,
            'quantity_delta' => 'decimal:4',
            'balance_after' => 'decimal:4',
            'estimated_cost_cents' => 'integer',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Ingredient, $this> */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<OperationPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(OperationPlan::class, 'operation_plan_id');
    }

    /** @return BelongsTo<OrderRecipeSnapshot, $this> */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(OrderRecipeSnapshot::class, 'order_recipe_snapshot_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
