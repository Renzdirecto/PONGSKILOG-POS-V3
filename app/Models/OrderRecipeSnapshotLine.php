<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $quantity_per_unit
 * @property int|null $cost_basis_cents
 * @property string|null $cost_basis_quantity
 */
#[Fillable(['order_recipe_snapshot_id', 'ingredient_id', 'quantity_per_unit', 'cost_basis_cents', 'cost_basis_quantity'])]
class OrderRecipeSnapshotLine extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new \LogicException('Order recipe snapshots are historical records.'));
        static::deleting(fn (): never => throw new \LogicException('Order recipe snapshots are historical records.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quantity_per_unit' => 'decimal:4',
            'cost_basis_cents' => 'integer',
            'cost_basis_quantity' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<OrderRecipeSnapshot, $this> */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(OrderRecipeSnapshot::class, 'order_recipe_snapshot_id');
    }

    /** @return BelongsTo<Ingredient, $this> */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
