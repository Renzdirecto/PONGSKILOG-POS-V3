<?php

namespace App\Models;

use Database\Factories\BranchInventoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['branch_id', 'product_id', 'on_hand', 'version'])]
class BranchInventory extends Model
{
    /** @use HasFactory<BranchInventoryFactory> */
    use HasFactory, HasUuids;

    protected $table = 'branch_inventory';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'on_hand' => 'integer',
            'version' => 'integer',
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
