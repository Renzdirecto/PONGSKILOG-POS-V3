<?php

namespace App\Models;

use App\Enums\ModifierSelectionType;
use App\Enums\ModifierSemanticRole;
use Database\Factories\ModifierGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property ModifierSelectionType $selection_type
 * @property ModifierSemanticRole|null $semantic_role
 */
#[Fillable(['name', 'semantic_role', 'selection_type', 'min_select', 'max_select', 'is_active'])]
class ModifierGroup extends Model
{
    /** @use HasFactory<ModifierGroupFactory> */
    use HasFactory, HasUuids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'semantic_role' => ModifierSemanticRole::class,
            'selection_type' => ModifierSelectionType::class,
            'min_select' => 'integer',
            'max_select' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<ModifierOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(ModifierOption::class);
    }

    /** @return BelongsToMany<Product, $this> */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_modifier_groups');
    }
}
