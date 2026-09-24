<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string|null $recommended_quantity
 * @property string $actual_quantity
 * @property string|null $estimated_unit_cost
 * @property string $actual_unit_cost
 * @property string $line_total
 * @property string|null $purchase_unit_size
 * @property string|null $base_quantity
 */
#[Fillable([
    'pamamalengke_purchase_id', 'line_type', 'ingredient_id', 'name_snapshot', 'unit_label', 'was_recommended',
    'recommended_quantity', 'actual_quantity', 'estimated_unit_cost', 'actual_unit_cost', 'line_total',
    'purchase_unit_size', 'base_quantity', 'ingredient_movement_id', 'note',
])]
class PamamalengkePurchaseItem extends Model
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
        return [
            'was_recommended' => 'boolean',
            'recommended_quantity' => 'decimal:4',
            'actual_quantity' => 'decimal:4',
            'estimated_unit_cost' => 'decimal:2',
            'actual_unit_cost' => 'decimal:2',
            'line_total' => 'decimal:2',
            'purchase_unit_size' => 'decimal:4',
            'base_quantity' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<PamamalengkePurchase, $this> */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(PamamalengkePurchase::class, 'pamamalengke_purchase_id');
    }

    /** @return BelongsTo<Ingredient, $this> */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
