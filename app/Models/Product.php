<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['category_id', 'name', 'description', 'default_price', 'image_path', 'is_active'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, HasUuids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'default_price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return HasMany<BranchInventory, $this> */
    public function inventoryBalances(): HasMany
    {
        return $this->hasMany(BranchInventory::class);
    }

    /** @return HasMany<BranchProduct, $this> */
    public function branchProducts(): HasMany
    {
        return $this->hasMany(BranchProduct::class);
    }

    /** @return BelongsToMany<ModifierGroup, $this> */
    public function modifierGroups(): BelongsToMany
    {
        return $this->belongsToMany(ModifierGroup::class, 'product_modifier_groups');
    }
}
