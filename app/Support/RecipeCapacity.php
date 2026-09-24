<?php

namespace App\Support;

use App\Enums\ModifierSelectionType;
use App\Enums\ModifierSemanticRole;
use App\Models\Branch;
use App\Models\BranchIngredientStock;
use App\Models\BranchProduct;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\ProductModifierEffect;
use App\Models\ProductModifierEffectLine;
use App\Models\Recipe;
use App\Models\RecipeLine;
use Illuminate\Validation\ValidationException;

/**
 * The one authority for Recipe-based Product availability. A Recipe-backed Product (it has at least one recipe, does
 * not track Product stock at the Branch and is not marked No recipe needed) can be sold only while its required Branch
 * Ingredient stock covers it:
 *
 *     servings = min over required Ingredients of floor(max(current stock, 0) / quantity per serving)
 *
 * The requirement of one serving is the base recipe of its Size (only the Size group defines base recipes) plus the
 * Product-specific effect of each selected Add-on / Modifier. Instructions never change it. Quantities are exact
 * ten-thousandths (ExactQuantity); a missing balance counts as zero and a negative balance gives zero servings.
 *
 * Products without any recipe keep their existing behaviour (not recipe-limited), and direct-resale Products keep the
 * existing Product-stock availability. React only renders the results of this class.
 *
 * @phpstan-type Requirement array<string, int>
 * @phpstan-type ProfileSize array{key: string, option_id: string|null, name: string, lines: Requirement|null}
 * @phpstan-type Profile array{product_id: string, name: string, mode: 'recipe'|'direct'|'none', conflict: list<string>|null, sizes: array<string, ProfileSize>, size_options: array<string, string>, effects: array<string, Requirement>}
 * @phpstan-type Selection array{product_id: string, quantity: int, modifiers: list<array{group_id: string, option_id: string}>}
 * @phpstan-type LineRequirement array{state: 'ok', size_key: string, lines: Requirement}|array{state: 'recipe_required'|'configuration_error', size_key: string|null, lines: null}
 * @phpstan-type SizeAvailability array{key: string, option_id: string|null, name: string, state: 'available'|'out_of_stock'|'recipe_required', capacity: int|null}
 * @phpstan-type CatalogAvailability array{state: 'available'|'out_of_stock'|'recipe_required'|'configuration_error', capacity: int|null, sizes: list<SizeAvailability>}
 */
class RecipeCapacity
{
    public function __construct(private ProductSizes $sizes) {}

    /**
     * Whole servings a per-serving requirement allows from the available quantities (missing = 0, negative = 0).
     *
     * @param  Requirement  $perServing
     * @param  array<string, int>  $available
     */
    public static function servings(array $perServing, array $available): int
    {
        if ($perServing === []) {
            throw new \LogicException('A recipe requirement needs at least one Ingredient.');
        }
        $servings = PHP_INT_MAX;
        foreach ($perServing as $ingredientId => $quantity) {
            if ($quantity <= 0) {
                throw new \LogicException('A recipe quantity must be positive.');
            }
            $servings = min($servings, intdiv(max($available[$ingredientId] ?? 0, 0), $quantity));
        }

        return $servings;
    }

    /**
     * Current Branch Ingredient stock by Ingredient (Ingredients without a balance row are absent, meaning zero).
     *
     * @param  list<string>  $ingredientIds
     * @return array<string, int>
     */
    public function stock(Branch $branch, array $ingredientIds): array
    {
        if ($ingredientIds === []) {
            return [];
        }

        return BranchIngredientStock::query()->where('branch_id', $branch->id)->whereIn('ingredient_id', array_values(array_unique($ingredientIds)))
            ->get(['ingredient_id', 'on_hand'])
            ->mapWithKeys(fn (BranchIngredientStock $stock): array => [$stock->ingredient_id => ExactQuantity::parse($stock->on_hand)])
            ->all();
    }

    /**
     * Recipe profiles (base recipe per Size and Product-specific Add-on effects) of Products at a Branch.
     *
     * @param  array<int, string>  $productIds  any Product ids; duplicates are ignored
     * @return array<string, Profile>
     */
    public function profiles(Branch $branch, array $productIds): array
    {
        $productIds = array_values(array_unique($productIds));
        if ($productIds === []) {
            return [];
        }
        $products = Product::query()->whereKey($productIds)->get(['id', 'name', 'no_recipe_needed'])->keyBy('id');
        $tracked = BranchProduct::query()->where('branch_id', $branch->id)->whereIn('product_id', $productIds)
            ->where('tracks_inventory', true)->pluck('product_id')->flip();
        $recipes = Recipe::query()->whereIn('product_id', $productIds)->with('lines:id,recipe_id,ingredient_id,quantity')->get()
            ->keyBy(fn (Recipe $recipe): string => $recipe->product_id.'|'.$recipe->size_key);
        $recipeProducts = $recipes->map(fn (Recipe $recipe): string => $recipe->product_id)->flip();
        $resolved = $this->sizes->resolve($productIds);
        /** Only Add-on / Modifier options (semantic_role null) carry an Ingredient effect; Size and Instruction never. */
        $addOnGroupIds = ModifierGroup::query()->whereNull('semantic_role')->select('id');
        $effects = ProductModifierEffect::query()->whereIn('product_id', $productIds)
            ->whereHas('option', fn ($query) => $query->whereIn('modifier_group_id', $addOnGroupIds))
            ->with('lines:id,product_modifier_effect_id,ingredient_id,quantity')->get();

        $profiles = [];
        foreach ($productIds as $productId) {
            $product = $products->get($productId);
            if ($product === null) {
                continue;
            }
            $mode = match (true) {
                $tracked->has($productId), $product->no_recipe_needed => 'direct',
                $recipeProducts->has($productId) => 'recipe',
                default => 'none',
            };
            $sizes = [];
            $sizeOptions = [];
            foreach ($resolved['sizes'][$productId] ?? [] as $size) {
                $recipe = $recipes->get($productId.'|'.$size['key']);
                $sizes[$size['key']] = [
                    'key' => $size['key'],
                    'option_id' => $size['option_id'],
                    'name' => $size['name'],
                    'lines' => $recipe === null || $recipe->lines->isEmpty() ? null : $this->requirement($recipe->lines),
                ];
                if ($size['option_id'] !== null) {
                    $sizeOptions[$size['option_id']] = $size['key'];
                }
            }
            $profileEffects = [];
            foreach ($effects->where('product_id', $productId) as $effect) {
                if ($effect->lines->isNotEmpty() && ! isset($sizeOptions[$effect->modifier_option_id])) {
                    $profileEffects[$effect->modifier_option_id] = $this->requirement($effect->lines);
                }
            }
            $profiles[$productId] = [
                'product_id' => $productId,
                'name' => $product->name,
                'mode' => $mode,
                'conflict' => $resolved['conflicts'][$productId] ?? null,
                'sizes' => $sizes,
                'size_options' => $sizeOptions,
                'effects' => $profileEffects,
            ];
        }

        return $profiles;
    }

    /**
     * Per-serving requirement of one configured line (its Size plus selected Add-ons), or null when the Product is not
     * recipe-limited. Options that are neither the Size nor an Add-on with an effect (Instructions included) add nothing.
     *
     * @param  Profile  $profile
     * @param  list<string>  $optionIds
     * @return LineRequirement|null
     */
    public function lineRequirement(array $profile, array $optionIds): ?array
    {
        if ($profile['mode'] !== 'recipe') {
            return null;
        }
        if ($profile['conflict'] !== null) {
            return ['state' => 'configuration_error', 'size_key' => null, 'lines' => null];
        }
        $sizeKey = Recipe::BASE_SIZE;
        foreach ($optionIds as $optionId) {
            if (isset($profile['size_options'][$optionId])) {
                $sizeKey = $profile['size_options'][$optionId];
                break;
            }
        }
        $lines = $profile['sizes'][$sizeKey]['lines'] ?? null;
        if ($lines === null) {
            return ['state' => 'recipe_required', 'size_key' => $sizeKey, 'lines' => null];
        }
        foreach (array_unique($optionIds) as $optionId) {
            foreach ($profile['effects'][$optionId] ?? [] as $ingredientId => $quantity) {
                $lines[$ingredientId] = ExactQuantity::add($lines[$ingredientId] ?? 0, $quantity);
            }
        }
        ksort($lines);

        return ['state' => 'ok', 'size_key' => $sizeKey, 'lines' => $lines];
    }

    /**
     * Recipe availability of each recipe-backed Product for the catalog tile and per-Size labels (null = not
     * recipe-limited: direct resale or no recipe yet, which keep their existing availability).
     *
     * @param  array<int, string>  $productIds  any Product ids; duplicates are ignored
     * @return array<string, CatalogAvailability|null>
     */
    public function catalog(Branch $branch, array $productIds): array
    {
        $profiles = $this->profiles($branch, $productIds);
        $stock = $this->stock($branch, $this->ingredientIds($profiles));
        $availability = [];
        foreach ($profiles as $productId => $profile) {
            if ($profile['mode'] !== 'recipe') {
                $availability[$productId] = null;

                continue;
            }
            if ($profile['conflict'] !== null) {
                $availability[$productId] = ['state' => 'configuration_error', 'capacity' => null, 'sizes' => []];

                continue;
            }
            $sizes = array_values(array_map(function (array $size) use ($stock): array {
                $capacity = $size['lines'] === null ? null : self::servings($size['lines'], $stock);

                return [
                    'key' => $size['key'],
                    'option_id' => $size['option_id'],
                    'name' => $size['name'],
                    'state' => match (true) {
                        $capacity === null => 'recipe_required',
                        $capacity > 0 => 'available',
                        default => 'out_of_stock',
                    },
                    'capacity' => $capacity,
                ];
            }, $profile['sizes']));
            $capacities = array_values(array_filter(array_column($sizes, 'capacity'), fn (?int $capacity): bool => $capacity !== null));
            $availability[$productId] = [
                'state' => match (true) {
                    $capacities !== [] && max($capacities) > 0 => 'available',
                    $capacities !== [] => 'out_of_stock',
                    default => 'recipe_required',
                },
                'capacity' => $capacities === [] ? null : max($capacities),
                'sizes' => $sizes,
            ];
        }

        return $availability;
    }

    /**
     * Capacity of one focus configuration after the other cart lines' requirements, and the capacity each Size or
     * Add-on option (with an Ingredient effect) of the focus Product would give if chosen with the current selection.
     *
     * @param  list<Selection>  $otherLines
     * @param  array{product_id: string, modifiers: list<array{group_id: string, option_id: string}>}  $focus
     * @return array{limited: bool, state: 'available'|'out_of_stock'|'recipe_required'|'configuration_error'|null, capacity: int|null, options: array<string, int>}
     */
    public function configuration(Branch $branch, array $otherLines, array $focus): array
    {
        $profiles = $this->profiles($branch, [$focus['product_id'], ...array_column($otherLines, 'product_id')]);
        $profile = $profiles[$focus['product_id']] ?? null;
        if ($profile === null || $profile['mode'] !== 'recipe') {
            return ['limited' => false, 'state' => null, 'capacity' => null, 'options' => []];
        }
        $remaining = $this->stock($branch, $this->ingredientIds($profiles));
        foreach ($otherLines as $line) {
            $requirement = isset($profiles[$line['product_id']]) ? $this->lineRequirement($profiles[$line['product_id']], array_column($line['modifiers'], 'option_id')) : null;
            foreach ($requirement['lines'] ?? [] as $ingredientId => $quantity) {
                $remaining[$ingredientId] = ($remaining[$ingredientId] ?? 0) - ExactQuantity::times($quantity, $line['quantity']);
            }
        }
        $selected = array_values(array_unique(array_column($focus['modifiers'], 'option_id')));
        $current = $this->lineRequirement($profile, $selected);
        if ($current === null || $current['state'] !== 'ok') {
            return ['limited' => true, 'state' => $current['state'] ?? 'recipe_required', 'capacity' => null, 'options' => $this->optionCapacities($profile, $focus, $selected, $remaining)];
        }
        $capacity = self::servings($current['lines'], $remaining);

        return [
            'limited' => true,
            'state' => $capacity > 0 ? 'available' : 'out_of_stock',
            'capacity' => $capacity,
            'options' => $this->optionCapacities($profile, $focus, $selected, $remaining),
        ];
    }

    /**
     * Rejects new order lines that are not sellable from current Branch Ingredient stock, aggregated across the whole
     * order (shared Ingredients across lines, Sizes and Add-ons). This read is an early, friendly check; the
     * authoritative check runs again under the Ingredient row locks when the sale is committed.
     *
     * @param  list<Selection>  $lines
     */
    public function assertOrderFits(Branch $branch, array $lines): void
    {
        $profiles = $this->profiles($branch, array_column($lines, 'product_id'));
        $required = [];
        $users = [];
        foreach ($lines as $index => $line) {
            $profile = $profiles[$line['product_id']] ?? null;
            $requirement = $profile === null ? null : $this->lineRequirement($profile, array_column($line['modifiers'], 'option_id'));
            if ($requirement === null) {
                continue;
            }
            if ($requirement['state'] === 'configuration_error') {
                throw ValidationException::withMessages(["items.$index.product_id" => ProductSizes::conflictMessage($profile['name'], $profile['conflict'] ?? [])]);
            }
            if ($requirement['state'] === 'recipe_required') {
                throw ValidationException::withMessages(["items.$index.product_id" => self::recipeRequiredMessage($profile, $requirement['size_key'])]);
            }
            foreach ($requirement['lines'] as $ingredientId => $quantity) {
                $required[$ingredientId] = ExactQuantity::add($required[$ingredientId] ?? 0, ExactQuantity::times($quantity, $line['quantity']));
                $users[$ingredientId][$profile['name']] = true;
            }
        }
        if ($required === []) {
            return;
        }
        $stock = $this->stock($branch, array_keys($required));
        $short = [];
        foreach ($required as $ingredientId => $quantity) {
            if (max($stock[$ingredientId] ?? 0, 0) < $quantity) {
                $short += $users[$ingredientId];
            }
        }
        if ($short !== []) {
            throw ValidationException::withMessages(['items' => 'Not enough ingredient stock for '.implode(', ', array_keys($short)).'. Reduce the quantity or remove an item.']);
        }
    }

    /** @param Profile $profile */
    public static function recipeRequiredMessage(array $profile, ?string $sizeKey): string
    {
        $size = $sizeKey === null || $sizeKey === Recipe::BASE_SIZE ? null : ($profile['sizes'][$sizeKey]['name'] ?? null);

        return ($size === null ? $profile['name'] : $size.' '.$profile['name']).' needs a recipe before it can be sold. Set it up in Operations › Recipes or choose another size.';
    }

    /**
     * @param  Profile  $profile
     * @param  array{product_id: string, modifiers: list<array{group_id: string, option_id: string}>}  $focus
     * @param  list<string>  $selected
     * @param  array<string, int>  $remaining
     * @return array<string, int>
     */
    private function optionCapacities(array $profile, array $focus, array $selected, array $remaining): array
    {
        if ($profile['conflict'] !== null) {
            return [];
        }
        $candidates = array_keys($profile['size_options'] + $profile['effects']);
        if ($candidates === []) {
            return [];
        }
        /** Single-choice groups replace their current option; multiple-choice groups add the candidate. */
        $groups = ModifierGroup::query()->whereHas('options', fn ($query) => $query->whereKey($candidates))
            ->with('options:id,modifier_group_id')->get(['id', 'selection_type', 'semantic_role']);
        $capacities = [];
        foreach ($groups as $group) {
            $groupOptions = $group->options->modelKeys();
            $single = $group->selection_type === ModifierSelectionType::Single || $group->semantic_role === ModifierSemanticRole::Size;
            foreach (array_intersect($groupOptions, $candidates) as $optionId) {
                $selection = $single ? array_values(array_diff($selected, $groupOptions)) : $selected;
                $selection[] = $optionId;
                $requirement = $this->lineRequirement($profile, array_values(array_unique($selection)));
                if ($requirement !== null && $requirement['state'] === 'ok') {
                    $capacities[$optionId] = self::servings($requirement['lines'], $remaining);
                } elseif ($requirement !== null) {
                    $capacities[$optionId] = 0;
                }
            }
        }

        return $capacities;
    }

    /**
     * @param  iterable<RecipeLine|ProductModifierEffectLine>  $lines
     * @return Requirement
     */
    private function requirement(iterable $lines): array
    {
        $requirement = [];
        foreach ($lines as $line) {
            $requirement[$line->ingredient_id] = ExactQuantity::parse($line->quantity);
        }
        ksort($requirement);

        return $requirement;
    }

    /**
     * @param  array<string, Profile>  $profiles
     * @return list<string>
     */
    private function ingredientIds(array $profiles): array
    {
        $ids = [];
        foreach ($profiles as $profile) {
            if ($profile['mode'] !== 'recipe') {
                continue;
            }
            foreach ($profile['sizes'] as $size) {
                $ids = [...$ids, ...array_keys($size['lines'] ?? [])];
            }
            foreach ($profile['effects'] as $effect) {
                $ids = [...$ids, ...array_keys($effect)];
            }
        }

        return array_values(array_unique($ids));
    }
}
