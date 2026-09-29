<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The Add-on effect in force when an Order first committed that Add-on on one Product/size snapshot. Immutable history;
 * a row without lines means the Add-on had no Ingredient effect for this Order.
 */
#[Fillable(['order_recipe_snapshot_id', 'modifier_option_id', 'option_name_snapshot', 'group_name_snapshot'])]
class OrderRecipeSnapshotModifier extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new \LogicException('Order recipe snapshots are historical records.'));
        static::deleting(fn (): never => throw new \LogicException('Order recipe snapshots are historical records.'));
    }

    /** @return BelongsTo<OrderRecipeSnapshot, $this> */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(OrderRecipeSnapshot::class, 'order_recipe_snapshot_id');
    }

    /** @return HasMany<OrderRecipeSnapshotModifierLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderRecipeSnapshotModifierLine::class);
    }
}
