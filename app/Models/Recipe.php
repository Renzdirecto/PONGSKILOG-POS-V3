<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** The current recipe of an existing Catalog Product size. Past sales keep their own Order recipe snapshots. */
#[Fillable(['product_id', 'size_modifier_option_id', 'size_key', 'updated_by_user_id'])]
class Recipe extends Model
{
    use HasUuids;

    public const BASE_SIZE = 'base';

    public static function sizeKey(?string $sizeOptionId): string
    {
        return $sizeOptionId ?? self::BASE_SIZE;
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<ModifierOption, $this> */
    public function sizeOption(): BelongsTo
    {
        return $this->belongsTo(ModifierOption::class, 'size_modifier_option_id');
    }

    /** @return HasMany<RecipeLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(RecipeLine::class);
    }
}
