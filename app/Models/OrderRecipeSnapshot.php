<?php

namespace App\Models;

use App\Enums\RecipeState;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The recipe, cost basis and Plan in force when an Order first committed one Product/size. Immutable history.
 *
 * @property RecipeState $recipe_state
 */
#[Fillable([
    'order_id', 'branch_id', 'product_id', 'size_modifier_option_id', 'size_key', 'product_name_snapshot',
    'size_name_snapshot', 'recipe_state', 'operation_plan_id',
])]
class OrderRecipeSnapshot extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new \LogicException('Order recipe snapshots are historical records.'));
        static::deleting(fn (): never => throw new \LogicException('Order recipe snapshots are historical records.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['recipe_state' => RecipeState::class];
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

    /** @return HasMany<OrderRecipeSnapshotLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderRecipeSnapshotLine::class);
    }

    /** @return HasMany<OrderRecipeSnapshotModifier, $this> */
    public function modifiers(): HasMany
    {
        return $this->hasMany(OrderRecipeSnapshotModifier::class);
    }
}
