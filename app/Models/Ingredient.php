<?php

namespace App\Models;

use App\Enums\ReplenishmentRule;
use Database\Factories\IngredientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An Ingredient configured for one Branch (its unit, target, purchase unit, cost and rule are that Branch's own). Its
 * physical stock is the Branch balance. Quantities are exact decimal strings in the base unit; read them through
 * App\Support\ExactQuantity, never as floats. `lineage_id` is the copy provenance used by Branch setup copies.
 *
 * @property string $branch_id
 * @property string $lineage_id
 * @property ReplenishmentRule $replenishment_rule
 * @property string $target_quantity
 * @property string|null $purchase_unit_size
 * @property string|null $purchase_unit_cost
 * @property string|null $reorder_point
 */
#[Fillable([
    'branch_id', 'lineage_id', 'name', 'icon', 'base_unit', 'target_quantity', 'purchase_unit_name', 'purchase_unit_size', 'purchase_unit_cost',
    'replenishment_rule', 'reorder_point', 'archived_at', 'created_by_user_id', 'updated_by_user_id',
])]
class Ingredient extends Model
{
    /** @use HasFactory<IngredientFactory> */
    use HasFactory, HasUuids;

    public const UNITS = ['pc', 'pack', 'bottle', 'ml', 'L', 'g', 'kg'];

    public const ICONS = ['lemon', 'bottle', 'drop', 'cup', 'tea', 'straw', 'leaf', 'egg', 'bowl', 'box'];

    /** A new record starts its own copy lineage; a Branch setup copy passes the source lineage explicitly. */
    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->lineage_id ??= $model->getKey();
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'replenishment_rule' => ReplenishmentRule::class,
            'target_quantity' => 'decimal:4',
            'purchase_unit_size' => 'decimal:4',
            'purchase_unit_cost' => 'decimal:2',
            'reorder_point' => 'decimal:4',
            'archived_at' => 'datetime',
        ];
    }

    /** @param Builder<Ingredient> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsToMany<OperationPlan, $this> */
    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(OperationPlan::class, 'operation_plan_ingredients');
    }

    /** @return HasMany<BranchIngredientStock, $this> */
    public function stocks(): HasMany
    {
        return $this->hasMany(BranchIngredientStock::class);
    }

    /** @return HasMany<IngredientMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(IngredientMovement::class);
    }

    /** @return HasMany<RecipeLine, $this> */
    public function recipeLines(): HasMany
    {
        return $this->hasMany(RecipeLine::class);
    }
}
