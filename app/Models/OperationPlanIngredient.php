<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Which Plans of a Branch show one of its Ingredients. An Ingredient may belong to several Plans of its Branch. */
#[Fillable(['branch_id', 'operation_plan_id', 'ingredient_id'])]
class OperationPlanIngredient extends Model
{
    use HasUuids;

    /** @return BelongsTo<OperationPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(OperationPlan::class, 'operation_plan_id');
    }

    /** @return BelongsTo<Ingredient, $this> */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
