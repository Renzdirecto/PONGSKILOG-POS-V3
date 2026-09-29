<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A working-list entry for the next pamamalengke run of one Branch + Plan: a manual item (never Ingredient stock) or
 * a "skip this run" mark for an automatic suggestion. Cleared when the run is confirmed.
 *
 * @property string|null $quantity
 * @property string|null $estimated_unit_cost
 */
#[Fillable([
    'branch_id', 'operation_plan_id', 'entry_type', 'ingredient_id', 'name', 'quantity', 'unit',
    'estimated_unit_cost', 'note', 'created_by_user_id',
])]
class PamamalengkeListEntry extends Model
{
    use HasUuids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'estimated_unit_cost' => 'decimal:2'];
    }

    /** @return BelongsTo<Ingredient, $this> */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
