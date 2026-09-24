<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The current Ingredient effect of one Add-on / Modifier option on one Product (for example Extra Yakult on Lemon
 * Yakult → Yakult +1 pc). Scoped to the Product because Modifier Groups are reusable across Products. Past sales keep
 * their own Order recipe snapshot modifiers.
 */
#[Fillable(['product_id', 'modifier_option_id', 'updated_by_user_id'])]
class ProductModifierEffect extends Model
{
    use HasUuids;

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<ModifierOption, $this> */
    public function option(): BelongsTo
    {
        return $this->belongsTo(ModifierOption::class, 'modifier_option_id');
    }

    /** @return HasMany<ProductModifierEffectLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(ProductModifierEffectLine::class);
    }
}
