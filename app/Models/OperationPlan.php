<?php

namespace App\Models;

use Database\Factories\OperationPlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A Pamalengke Plan: an organizational, planning and reporting scope over existing Catalog Products and Ingredients.
 * A Plan never owns stock; Ingredient stock is one canonical balance per Branch + Ingredient.
 */
#[Fillable(['name', 'description', 'icon', 'archived_at', 'created_by_user_id'])]
class OperationPlan extends Model
{
    /** @use HasFactory<OperationPlanFactory> */
    use HasFactory, HasUuids;

    public const ICONS = ['glass', 'meal', 'bowl', 'pot', 'box'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }

    /** @param Builder<OperationPlan> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /** @return BelongsToMany<Product, $this> */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'operation_plan_products');
    }

    /** @return BelongsToMany<Ingredient, $this> */
    public function ingredients(): BelongsToMany
    {
        return $this->belongsToMany(Ingredient::class, 'operation_plan_ingredients');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
