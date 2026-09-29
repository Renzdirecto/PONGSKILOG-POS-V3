<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The one canonical Ingredient balance for a Branch. Written only by App\Actions\Operations\ApplyIngredientMovement
 * together with its append-only movement; it may be negative after a sale (a count then needs attention).
 *
 * @property string $on_hand
 */
#[Fillable(['branch_id', 'ingredient_id', 'on_hand', 'version'])]
class BranchIngredientStock extends Model
{
    use HasUuids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['on_hand' => 'decimal:4', 'version' => 'integer'];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Ingredient, $this> */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
