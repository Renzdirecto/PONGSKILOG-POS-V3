<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Which Plans show an Ingredient. An Ingredient may belong to several Plans and still has one stock per Branch. */
#[Fillable(['operation_plan_id', 'ingredient_id'])]
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
