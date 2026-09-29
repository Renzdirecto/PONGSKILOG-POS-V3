<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property string $quantity */
#[Fillable(['product_modifier_effect_id', 'ingredient_id', 'quantity'])]
class ProductModifierEffectLine extends Model
{
    use HasUuids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['quantity' => 'decimal:4'];
    }

    /** @return BelongsTo<ProductModifierEffect, $this> */
    public function effect(): BelongsTo
    {
        return $this->belongsTo(ProductModifierEffect::class, 'product_modifier_effect_id');
    }

    /** @return BelongsTo<Ingredient, $this> */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
