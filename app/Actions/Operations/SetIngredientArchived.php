<?php

namespace App\Actions\Operations;

use App\Actions\Audit\AuditRecorder;
use App\Models\Ingredient;
use App\Models\PamamalengkeListEntry;
use App\Models\ProductModifierEffectLine;
use App\Models\RecipeLine;
use App\Models\User;
use App\Support\OperationsAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Archives or restores an Ingredient. Nothing is deleted: its balances, movements, purchase history and snapshotted
 * costs stay readable. An Ingredient still used by a current recipe cannot be archived, so no future sale can reference
 * an archived Ingredient.
 */
class SetIngredientArchived
{
    public function __construct(private OperationsAccess $access, private AuditRecorder $audit) {}

    public function execute(User $actor, Ingredient $ingredient, bool $archived): Ingredient
    {
        $actor = $this->access->authorizeDefinitions($actor);

        return DB::transaction(function () use ($actor, $ingredient, $archived): Ingredient {
            $ingredient = Ingredient::query()->whereKey($ingredient->id)->lock('for no key update')->firstOrFail();
            if (($ingredient->archived_at !== null) === $archived) {
                return $ingredient;
            }
            if ($archived && RecipeLine::query()->where('ingredient_id', $ingredient->id)->exists()) {
                throw ValidationException::withMessages(['ingredient' => $ingredient->name.' is still used in a recipe. Remove it from those recipes first.']);
            }
            if ($archived && ProductModifierEffectLine::query()->where('ingredient_id', $ingredient->id)->exists()) {
                throw ValidationException::withMessages(['ingredient' => $ingredient->name.' is still used by an add-on ingredient effect. Remove it from those effects first.']);
            }
            if ($archived) {
                PamamalengkeListEntry::query()->where('ingredient_id', $ingredient->id)->delete();
            }
            $ingredient->update(['archived_at' => $archived ? now() : null, 'updated_by_user_id' => $actor->id]);

            $this->audit->record(
                branch: null,
                actor: $actor,
                module: 'operations',
                action: $archived ? 'ingredient.archived' : 'ingredient.restored',
                auditableType: Ingredient::class,
                auditableId: $ingredient->id,
                after: ['name' => $ingredient->name, 'archived' => $archived],
            );

            return $ingredient;
        });
    }
}
