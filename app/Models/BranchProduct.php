<?php

namespace App\Models;

use Database\Factories\BranchProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Explicit Branch assortment membership and the Branch configuration of one global Product: no row means the Product is
 * not sold at that Branch; `is_available = false` means it still belongs to the Branch but is temporarily unavailable.
 * `no_recipe_needed` is the Branch recipe mode (direct, no Ingredient recipe); `tracks_inventory` uses Product stock.
 */
#[Fillable(['branch_id', 'product_id', 'price_override', 'is_available', 'tracks_inventory', 'low_stock_threshold', 'no_recipe_needed'])]
class BranchProduct extends Model
{
    /** @use HasFactory<BranchProductFactory> */
    use HasFactory, HasUuids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'price_override' => 'decimal:2',
            'is_available' => 'boolean',
            'tracks_inventory' => 'boolean',
            'no_recipe_needed' => 'boolean',
            'low_stock_threshold' => 'integer',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
