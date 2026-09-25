<?php

namespace App\Actions\Operations;

use App\Actions\Audit\AuditRecorder;
use App\Enums\BranchStatus;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Ingredient;
use App\Models\OperationPlan;
use App\Models\OperationPlanIngredient;
use App\Models\OperationPlanProduct;
use App\Models\ProductModifierEffect;
use App\Models\ProductModifierEffectLine;
use App\Models\Recipe;
use App\Models\RecipeLine;
use App\Models\User;
use App\Support\BranchConfiguration;
use App\Support\CatalogRealtime;
use App\Support\OperationsAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Copies Operations CONFIGURATION from one Branch into another, once (a clone, never a live link):
 *
 * - Plans (name, description, icon), their Ingredient links and the Plan of each copied Product;
 * - Ingredients (unit, target, purchase unit, purchase cost, replenishment rule, reorder point);
 * - the recipe mode of each Product, its Recipes per Size and its Add-on Ingredient effects.
 *
 * It never copies physical or historical data: no Ingredient or Product stock, movements, purchases, Store Sessions,
 * sales, Order snapshots or audit. Destination stock starts from its own state. Only Products already in the destination
 * assortment receive recipe/Plan configuration (the Product copy adds them first in the same transaction).
 *
 * Destination records are matched by copy lineage first (the same copied record), then by name within the destination
 * (names are unique per Branch). Existing destination configuration is kept by default; `replace` explicitly overwrites
 * the matched configuration for future sales only. A matched Ingredient counted in another unit cannot be reused: its
 * dependent recipes are skipped and reported. Both Branches are locked (BranchConfiguration::lockCopy), so concurrent
 * copies serialize and never create duplicates. The dry run (preview) and the copy share one code path.
 *
 * @phpstan-type CopyResult array{
 *     products: int,
 *     not_in_destination: int,
 *     plans: array{new: int, existing: int, replaced: int},
 *     ingredients: array{new: int, existing: int, replaced: int, conflicts: int},
 *     plan_products: array{assigned: int, moved: int, kept: int},
 *     recipes: array{products: int, recipes: int, effects: int, direct: int, replaced: int, kept: int},
 *     skipped: list<string>
 * }
 */
class CopyOperationsSetup
{
    public const SECTIONS = ['plans', 'ingredients', 'recipes'];

    private const SETTINGS = [
        'icon', 'base_unit', 'target_quantity', 'purchase_unit_name', 'purchase_unit_size', 'purchase_unit_cost',
        'replenishment_rule', 'reorder_point',
    ];

    public function __construct(private OperationsAccess $access, private AuditRecorder $audit, private CatalogRealtime $realtime) {}

    /**
     * Branches this account may copy Operations setup from into $destination: every other active Branch for a
     * business-wide account, only its other assigned active Branches for a Branch-scoped one.
     *
     * @return list<array{id: string, name: string, code: string}>
     */
    public static function sources(User $actor, Branch $destination): array
    {
        $query = $actor->hasBusinessWideScope()
            ? Branch::query()
            : $actor->branches()->wherePivot('is_active', true);

        return array_values($query->where('branches.status', BranchStatus::Active)
            ->whereKeyNot($destination->id)
            ->orderBy('branches.name')->orderBy('branches.code')
            ->get(['branches.id', 'branches.name', 'branches.code'])
            ->map(fn (Branch $branch): array => ['id' => (string) $branch->id, 'name' => $branch->name, 'code' => $branch->code])
            ->all());
    }

    /**
     * What a standalone copy would do now, without writing anything.
     *
     * @param  array<int, mixed>  $sections
     * @return CopyResult
     */
    public function preview(User $actor, Branch $source, Branch $destination, array $sections, bool $replace): array
    {
        $actor = $this->authorize($actor, $source, $destination);

        return $this->run($actor, $source, $destination, null, $this->sections($sections), $replace, apply: false);
    }

    /**
     * Operations › Copy setup from another Branch: the chosen sections for every Product both Branches sell.
     *
     * @param  array<int, mixed>  $sections
     * @return CopyResult
     */
    public function execute(User $actor, Branch $source, Branch $destination, array $sections, bool $replace): array
    {
        $actor = $this->authorize($actor, $source, $destination);
        $sections = $this->sections($sections);

        return DB::transaction(function () use ($actor, $source, $destination, $sections, $replace): array {
            [$source, $destination] = BranchConfiguration::lockCopy($source, $destination);
            $result = $this->run($actor, $source, $destination, null, $sections, $replace, apply: true);
            $this->record($actor, $source, $destination, $result, $sections, $replace, null);

            return $result;
        });
    }

    /**
     * The Operations part of "Copy products from another Branch": every section, for the selected Products only. It runs
     * inside the caller's transaction after the Products were added, with both Branches already locked.
     *
     * @param  list<string>  $productIds
     * @return CopyResult
     */
    public function copyForProducts(User $actor, Branch $source, Branch $destination, array $productIds, bool $replace): array
    {
        $actor = $this->authorize($actor, $source, $destination);
        $result = $this->run($actor, $source, $destination, $productIds, self::SECTIONS, $replace, apply: true);
        $this->record($actor, $source, $destination, $result, self::SECTIONS, $replace, $productIds);

        return $result;
    }

    /**
     * The source-side Operations setup of each Product for the Product copy review, and each Plan and Ingredient it
     * brings along with its destination state, so the review can total any selection without another request.
     *
     * @return array{products: array<string, array{mode: string, plan_id: string|null, recipes: int, effects: int, ingredient_ids: list<string>, destination_configured: bool}>, plans: array<string, array{name: string, exists: bool}>, ingredients: array<string, array{name: string, exists: bool, conflict: bool}>}
     */
    public function productDetails(User $actor, Branch $source, Branch $destination): array
    {
        $this->authorize($actor, $source, $destination);
        $sourceConfigs = BranchProduct::query()->where('branch_id', $source->id)->get()->keyBy('product_id');
        $destinationConfigs = BranchProduct::query()->where('branch_id', $destination->id)->get()->keyBy('product_id');
        $recipes = Recipe::query()->where('branch_id', $source->id)->with('lines:id,recipe_id,ingredient_id')->get()->groupBy('product_id');
        $effects = ProductModifierEffect::query()->where('branch_id', $source->id)->with('lines:id,product_modifier_effect_id,ingredient_id')->get()->groupBy('product_id');
        $destinationRecipes = Recipe::query()->where('branch_id', $destination->id)->distinct()->pluck('product_id')->flip();
        $destinationEffects = ProductModifierEffect::query()->where('branch_id', $destination->id)->distinct()->pluck('product_id')->flip();
        $plans = OperationPlan::query()->where('branch_id', $source->id)->whereNull('archived_at')->get()->keyBy('id');
        $planOf = OperationPlanProduct::query()->where('branch_id', $source->id)->whereIn('operation_plan_id', $plans->modelKeys())->pluck('operation_plan_id', 'product_id');
        $planIngredients = OperationPlanIngredient::query()->whereIn('operation_plan_id', $plans->modelKeys())->get()->groupBy('operation_plan_id');
        $ingredients = Ingredient::query()->where('branch_id', $source->id)->whereNull('archived_at')->get()->keyBy('id');
        [$destinationByLineage, $destinationByName] = $this->destinationIngredients($destination);
        [$destinationPlansByLineage, $destinationPlansByName] = $this->destinationPlans($destination);

        $products = [];
        foreach ($sourceConfigs as $productId => $configuration) {
            $productRecipes = $recipes->get($productId) ?? new Collection;
            $productEffects = $effects->get($productId) ?? new Collection;
            $planId = isset($planOf[$productId]) ? (string) $planOf[$productId] : null;
            $ingredientIds = [
                ...$productRecipes->flatMap(fn (Recipe $recipe) => $recipe->lines->pluck('ingredient_id'))->all(),
                ...$productEffects->flatMap(fn (ProductModifierEffect $effect) => $effect->lines->pluck('ingredient_id'))->all(),
                ...($planId === null ? [] : ($planIngredients->get($planId)?->pluck('ingredient_id')->all() ?? [])),
            ];
            $destinationConfig = $destinationConfigs->get($productId);
            $products[(string) $productId] = [
                'mode' => $this->mode($configuration, $productRecipes->isNotEmpty() || $productEffects->isNotEmpty()),
                'plan_id' => $planId,
                'recipes' => $productRecipes->count(),
                'effects' => $productEffects->count(),
                'ingredient_ids' => array_values(array_unique(array_filter(array_map('strval', $ingredientIds), fn (string $id): bool => $ingredients->has($id)))),
                'destination_configured' => $destinationConfig !== null && ($destinationConfig->no_recipe_needed || $destinationRecipes->has($productId) || $destinationEffects->has($productId)),
            ];
        }

        return [
            'products' => $products,
            'plans' => $plans->mapWithKeys(fn (OperationPlan $plan): array => [$plan->id => [
                'name' => $plan->name,
                'exists' => ($destinationPlansByLineage->get($plan->lineage_id) ?? $destinationPlansByName->get(mb_strtolower($plan->name))) !== null,
            ]])->all(),
            'ingredients' => $ingredients->mapWithKeys(function (Ingredient $ingredient) use ($destinationByLineage, $destinationByName): array {
                $match = $destinationByLineage->get($ingredient->lineage_id) ?? $destinationByName->get(mb_strtolower($ingredient->name));

                return [$ingredient->id => [
                    'name' => $ingredient->name,
                    'exists' => $match !== null,
                    'conflict' => $match !== null && ($match->base_unit !== $ingredient->base_unit || $match->archived_at !== null),
                ]];
            })->all(),
        ];
    }

    /**
     * @param  list<string>|null  $productIds  null = every Product both Branches sell
     * @param  list<string>  $sections
     * @return CopyResult
     */
    private function run(User $actor, Branch $source, Branch $destination, ?array $productIds, array $sections, bool $replace, bool $apply): array
    {
        $result = [
            'products' => 0,
            'not_in_destination' => 0,
            'plans' => ['new' => 0, 'existing' => 0, 'replaced' => 0],
            'ingredients' => ['new' => 0, 'existing' => 0, 'replaced' => 0, 'conflicts' => 0],
            'plan_products' => ['assigned' => 0, 'moved' => 0, 'kept' => 0],
            'recipes' => ['products' => 0, 'recipes' => 0, 'effects' => 0, 'direct' => 0, 'replaced' => 0, 'kept' => 0],
            'skipped' => [],
        ];
        $wants = array_flip($sections);
        $sourceConfigs = BranchProduct::query()->where('branch_id', $source->id)->get()->keyBy('product_id');
        $destinationConfigs = BranchProduct::query()->where('branch_id', $destination->id)->get()->keyBy('product_id');
        $scope = $productIds === null ? $sourceConfigs->keys()->map(fn ($id): string => (string) $id)->all()
            : array_values(array_intersect(array_map('strval', $productIds), $sourceConfigs->keys()->map(fn ($id): string => (string) $id)->all()));
        $products = array_values(array_filter($scope, fn (string $id): bool => $destinationConfigs->has($id)));
        sort($products);
        $result['products'] = count($products);
        $result['not_in_destination'] = count($scope) - count($products);

        $sourceRecipes = isset($wants['recipes'])
            ? Recipe::query()->where('branch_id', $source->id)->whereIn('product_id', $products)->with('lines')->orderBy('product_id')->orderBy('size_key')->get()
            : new Collection;
        $sourceEffects = isset($wants['recipes'])
            ? ProductModifierEffect::query()->where('branch_id', $source->id)->whereIn('product_id', $products)->with('lines')->orderBy('product_id')->orderBy('modifier_option_id')->get()
            : new Collection;
        $sourcePlanProducts = OperationPlanProduct::query()->where('operation_plan_products.branch_id', $source->id)
            ->whereIn('operation_plan_products.product_id', $products)
            ->whereIn('operation_plan_products.operation_plan_id', OperationPlan::query()->where('branch_id', $source->id)->whereNull('archived_at')->select('id'))
            ->orderBy('product_id')->get();
        $sourcePlans = isset($wants['plans'])
            ? OperationPlan::query()->where('branch_id', $source->id)->whereNull('archived_at')
                ->when($productIds !== null, fn ($query) => $query->whereKey($sourcePlanProducts->pluck('operation_plan_id')->all()))
                ->orderBy('name')->orderBy('id')->get()
            : new Collection;
        $sourcePlanIngredients = OperationPlanIngredient::query()->whereIn('operation_plan_id', $sourcePlans->modelKeys())->orderBy('id')->get();

        $ingredientIds = [
            ...$sourceRecipes->flatMap(fn (Recipe $recipe) => $recipe->lines->pluck('ingredient_id'))->all(),
            ...$sourceEffects->flatMap(fn (ProductModifierEffect $effect) => $effect->lines->pluck('ingredient_id'))->all(),
            ...$sourcePlanIngredients->pluck('ingredient_id')->all(),
        ];
        $sourceIngredients = Ingredient::query()->where('branch_id', $source->id)->whereNull('archived_at')
            ->when(! (isset($wants['ingredients']) && $productIds === null), fn ($query) => $query->whereKey(array_values(array_unique(array_map('strval', $ingredientIds)))))
            ->orderBy('name')->orderBy('id')->get();

        $ingredientMap = $this->copyIngredients($actor, $destination, $sourceIngredients, $replace, $apply, $result);
        $planMap = $this->copyPlans($actor, $destination, $sourcePlans, $replace, $apply, $result);

        if ($apply) {
            foreach ($sourcePlanIngredients as $link) {
                $planId = $planMap[$link->operation_plan_id] ?? null;
                $ingredientId = $ingredientMap[$link->ingredient_id] ?? null;
                if ($planId !== null && $ingredientId !== null) {
                    OperationPlanIngredient::query()->insertOrIgnore([[
                        'id' => (string) str()->uuid(),
                        'branch_id' => $destination->id,
                        'operation_plan_id' => $planId,
                        'ingredient_id' => $ingredientId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]]);
                }
            }
        }

        if (isset($wants['plans'])) {
            $current = OperationPlanProduct::query()->where('branch_id', $destination->id)->whereIn('product_id', $products)->get()->keyBy('product_id');
            foreach ($sourcePlanProducts as $row) {
                $planId = $planMap[$row->operation_plan_id] ?? null;
                if ($planId === null) {
                    continue;
                }
                $assignment = $current->get($row->product_id);
                if ($assignment === null) {
                    $result['plan_products']['assigned']++;
                    if ($apply) {
                        OperationPlanProduct::query()->create(['branch_id' => $destination->id, 'operation_plan_id' => $planId, 'product_id' => $row->product_id]);
                    }
                } elseif ($assignment->operation_plan_id !== $planId && $replace) {
                    $result['plan_products']['moved']++;
                    if ($apply) {
                        $assignment->update(['operation_plan_id' => $planId]);
                    }
                } else {
                    $result['plan_products']['kept']++;
                }
            }
        }

        if (isset($wants['recipes'])) {
            $this->copyRecipes($actor, $destination, $products, $sourceConfigs, $destinationConfigs, $sourceRecipes, $sourceEffects, $ingredientMap, $replace, $apply, $result);
        }
        $result['skipped'] = array_slice($result['skipped'], 0, 25);

        return $result;
    }

    /**
     * @param  Collection<int, Ingredient>  $sourceIngredients
     * @param  CopyResult  $result
     * @return array<string, string|null> source Ingredient id → destination Ingredient id (null = conflict)
     */
    private function copyIngredients(User $actor, Branch $destination, Collection $sourceIngredients, bool $replace, bool $apply, array &$result): array
    {
        [$byLineage, $byName] = $this->destinationIngredients($destination);
        $map = [];
        foreach ($sourceIngredients as $ingredient) {
            $settings = collect(self::SETTINGS)->mapWithKeys(fn (string $field): array => [$field => $ingredient->getAttribute($field)])->all();
            /** @var Ingredient|null $match */
            $match = $byLineage->get($ingredient->lineage_id) ?? $byName->get(mb_strtolower($ingredient->name));
            if ($match === null) {
                $result['ingredients']['new']++;
                $map[$ingredient->id] = $apply ? Ingredient::query()->create([
                    ...$settings,
                    'branch_id' => $destination->id,
                    'lineage_id' => $ingredient->lineage_id,
                    'name' => $ingredient->name,
                    'created_by_user_id' => $actor->id,
                ])->id : 'new:'.$ingredient->id;

                continue;
            }
            $unitDiffers = $match->base_unit !== $ingredient->base_unit;
            if ($replace && (! $unitDiffers || ! SaveIngredient::unitLocked($match))) {
                $result['ingredients']['replaced']++;
                if ($apply) {
                    $renamed = $match->name !== $ingredient->name && ! $byName->has(mb_strtolower($ingredient->name));
                    $match->update([...$settings, ...($renamed ? ['name' => $ingredient->name] : []), 'archived_at' => null, 'updated_by_user_id' => $actor->id]);
                }
                $map[$ingredient->id] = $match->id;

                continue;
            }
            if ($unitDiffers || $match->archived_at !== null) {
                $result['ingredients']['conflicts']++;
                $result['skipped'][] = $unitDiffers
                    ? $ingredient->name.' is counted in '.$match->base_unit.' at '.$destination->code.' (not '.$ingredient->base_unit.'), so it was not reused.'
                    : $ingredient->name.' is archived at '.$destination->code.'. Restore it there or choose Replace.';
                $map[$ingredient->id] = null;

                continue;
            }
            $result['ingredients']['existing']++;
            $map[$ingredient->id] = $match->id;
        }

        return $map;
    }

    /**
     * @param  Collection<int, OperationPlan>  $sourcePlans
     * @param  CopyResult  $result
     * @return array<string, string|null> source Plan id → destination Plan id (null = not copied)
     */
    private function copyPlans(User $actor, Branch $destination, Collection $sourcePlans, bool $replace, bool $apply, array &$result): array
    {
        [$byLineage, $byName] = $this->destinationPlans($destination);
        $map = [];
        foreach ($sourcePlans as $plan) {
            /** @var OperationPlan|null $match */
            $match = $byLineage->get($plan->lineage_id) ?? $byName->get(mb_strtolower($plan->name));
            if ($match === null) {
                $result['plans']['new']++;
                $map[$plan->id] = $apply ? OperationPlan::query()->create([
                    'branch_id' => $destination->id,
                    'lineage_id' => $plan->lineage_id,
                    'name' => $plan->name,
                    'description' => $plan->description,
                    'icon' => $plan->icon,
                    'created_by_user_id' => $actor->id,
                ])->id : 'new:'.$plan->id;

                continue;
            }
            if ($replace) {
                $result['plans']['replaced']++;
                if ($apply) {
                    $renamed = $match->name !== $plan->name && ! $byName->has(mb_strtolower($plan->name));
                    $match->update(['description' => $plan->description, 'icon' => $plan->icon, 'archived_at' => null, ...($renamed ? ['name' => $plan->name] : [])]);
                }
                $map[$plan->id] = $match->id;

                continue;
            }
            if ($match->archived_at !== null) {
                $result['skipped'][] = 'The '.$plan->name.' Plan is archived at '.$destination->code.'. Choose Replace to restore it.';
                $map[$plan->id] = null;

                continue;
            }
            $result['plans']['existing']++;
            $map[$plan->id] = $match->id;
        }

        return $map;
    }

    /**
     * @param  list<string>  $products
     * @param  \Illuminate\Support\Collection<string, BranchProduct>  $sourceConfigs
     * @param  \Illuminate\Support\Collection<string, BranchProduct>  $destinationConfigs
     * @param  Collection<int, Recipe>  $sourceRecipes
     * @param  Collection<int, ProductModifierEffect>  $sourceEffects
     * @param  array<string, string|null>  $ingredientMap
     * @param  CopyResult  $result
     */
    private function copyRecipes(User $actor, Branch $destination, array $products, $sourceConfigs, $destinationConfigs, Collection $sourceRecipes, Collection $sourceEffects, array $ingredientMap, bool $replace, bool $apply, array &$result): void
    {
        $destinationRecipes = Recipe::query()->where('branch_id', $destination->id)->whereIn('product_id', $products)->distinct()->pluck('product_id')->flip();
        $destinationEffects = ProductModifierEffect::query()->where('branch_id', $destination->id)->whereIn('product_id', $products)->distinct()->pluck('product_id')->flip();
        $names = DB::table('products')->whereIn('id', $products)->pluck('name', 'id');

        foreach ($products as $productId) {
            /** @var BranchProduct $sourceConfig */
            $sourceConfig = $sourceConfigs->get($productId);
            /** @var BranchProduct $destinationConfig */
            $destinationConfig = $destinationConfigs->get($productId);
            $recipes = $sourceRecipes->where('product_id', $productId);
            $effects = $sourceEffects->where('product_id', $productId);
            $mode = $this->mode($sourceConfig, $recipes->isNotEmpty() || $effects->isNotEmpty());
            if (! in_array($mode, ['recipe', 'direct'], true)) {
                continue;
            }
            $name = (string) ($names[$productId] ?? 'A product');
            $configured = $destinationConfig->no_recipe_needed || $destinationRecipes->has($productId) || $destinationEffects->has($productId);
            if ($configured && ! $replace) {
                $result['recipes']['kept']++;

                continue;
            }
            if ($mode === 'recipe' && $destinationConfig->tracks_inventory) {
                $result['skipped'][] = $name.' tracks Product stock at '.$destination->code.', so its recipe was not copied.';

                continue;
            }
            $needed = [
                ...$recipes->flatMap(fn (Recipe $recipe) => $recipe->lines->pluck('ingredient_id'))->all(),
                ...$effects->flatMap(fn (ProductModifierEffect $effect) => $effect->lines->pluck('ingredient_id'))->all(),
            ];
            if (array_filter($needed, fn (string $id): bool => ($ingredientMap[$id] ?? null) === null) !== []) {
                $result['skipped'][] = $name.' uses an ingredient that could not be copied to '.$destination->code.', so its recipe was not copied.';

                continue;
            }

            $result['recipes']['products']++;
            $result['recipes']['recipes'] += $recipes->count();
            $result['recipes']['effects'] += $effects->count();
            $result['recipes']['direct'] += $mode === 'direct' ? 1 : 0;
            $result['recipes']['replaced'] += $configured ? 1 : 0;
            if (! $apply) {
                continue;
            }
            Recipe::query()->where('branch_id', $destination->id)->where('product_id', $productId)->delete();
            ProductModifierEffect::query()->where('branch_id', $destination->id)->where('product_id', $productId)->delete();
            $destinationConfig->update(['no_recipe_needed' => $mode === 'direct']);
            foreach ($recipes as $recipe) {
                $copy = Recipe::query()->create([
                    'branch_id' => $destination->id,
                    'product_id' => $productId,
                    'size_modifier_option_id' => $recipe->size_modifier_option_id,
                    'size_key' => $recipe->size_key,
                    'updated_by_user_id' => $actor->id,
                ]);
                foreach ($recipe->lines as $line) {
                    RecipeLine::query()->create(['recipe_id' => $copy->id, 'ingredient_id' => $ingredientMap[$line->ingredient_id], 'quantity' => $line->quantity]);
                }
            }
            foreach ($effects as $effect) {
                $copy = ProductModifierEffect::query()->create([
                    'branch_id' => $destination->id,
                    'product_id' => $productId,
                    'modifier_option_id' => $effect->modifier_option_id,
                    'updated_by_user_id' => $actor->id,
                ]);
                foreach ($effect->lines as $line) {
                    ProductModifierEffectLine::query()->create(['product_modifier_effect_id' => $copy->id, 'ingredient_id' => $ingredientMap[$line->ingredient_id], 'quantity' => $line->quantity]);
                }
            }
        }
    }

    /** product_stock and direct are the source's modes; recipe needs recipes or effects; none has no Operations setup. */
    private function mode(BranchProduct $configuration, bool $hasRecipe): string
    {
        return match (true) {
            $configuration->tracks_inventory => 'product_stock',
            $configuration->no_recipe_needed => 'direct',
            $hasRecipe => 'recipe',
            default => 'none',
        };
    }

    /** @return array{0: \Illuminate\Support\Collection<string, Ingredient>, 1: \Illuminate\Support\Collection<string, Ingredient>} */
    private function destinationIngredients(Branch $destination): array
    {
        $ingredients = Ingredient::query()->where('branch_id', $destination->id)->orderBy('id')->get();

        return [$ingredients->keyBy('lineage_id'), $ingredients->keyBy(fn (Ingredient $ingredient): string => mb_strtolower($ingredient->name))];
    }

    /** @return array{0: \Illuminate\Support\Collection<string, OperationPlan>, 1: \Illuminate\Support\Collection<string, OperationPlan>} */
    private function destinationPlans(Branch $destination): array
    {
        $plans = OperationPlan::query()->where('branch_id', $destination->id)->orderBy('id')->get();

        return [
            $plans->keyBy('lineage_id'),
            $plans->whereNull('archived_at')->keyBy(fn (OperationPlan $plan): string => mb_strtolower($plan->name)),
        ];
    }

    /**
     * @param  array<int, mixed>  $sections
     * @return list<string>
     */
    private function sections(array $sections): array
    {
        $given = array_map(fn (mixed $section): string => is_string($section) ? $section : '', $sections);
        $chosen = array_values(array_intersect(self::SECTIONS, $given));
        if ($chosen === [] || array_diff($given, self::SECTIONS) !== []) {
            throw ValidationException::withMessages(['sections' => 'Choose what to copy: Plans, Ingredients or Recipes.']);
        }

        return $chosen;
    }

    /**
     * Operations access, both Branches readable/configurable by this account, and a different active source. An
     * unauthorized source is reported like an unknown one.
     */
    private function authorize(User $actor, Branch $source, Branch $destination): User
    {
        $actor = $this->access->authorize($actor);
        if (! $actor->canAccessBranch($source) || ! $actor->canAccessBranch($destination)) {
            throw new AuthorizationException('This account may not copy Operations setup between these Branches.');
        }
        if ($source->is($destination)) {
            throw ValidationException::withMessages(['source_branch_id' => 'Choose a different Branch to copy from.']);
        }
        if ($source->status !== BranchStatus::Active) {
            throw ValidationException::withMessages(['source_branch_id' => 'Choose an active Branch to copy from.']);
        }

        return $actor;
    }

    /**
     * @param  CopyResult  $result
     * @param  list<string>  $sections
     * @param  list<string>|null  $productIds
     */
    private function record(User $actor, Branch $source, Branch $destination, array $result, array $sections, bool $replace, ?array $productIds): void
    {
        $changed = $result['plans']['new'] + $result['plans']['replaced'] + $result['ingredients']['new'] + $result['ingredients']['replaced']
            + $result['plan_products']['assigned'] + $result['plan_products']['moved'] + $result['recipes']['products'];
        if ($changed === 0) {
            return;
        }
        $this->audit->record(
            branch: $destination,
            actor: $actor,
            module: 'operations',
            action: 'operations.setup_copied',
            auditableType: Branch::class,
            auditableId: $destination->id,
            after: [
                'source_branch_code' => $source->code,
                'destination_branch_code' => $destination->code,
                'sections' => $sections,
                'replace' => $replace,
                'product_count' => $productIds === null ? null : count($productIds),
            ],
            metadata: [
                'plans' => $result['plans'],
                'ingredients' => $result['ingredients'],
                'plan_products' => $result['plan_products'],
                'recipes' => $result['recipes'],
                'skipped' => count($result['skipped']),
            ],
        );
        /** Recipe modes, recipes and effects change POS availability of the destination only (after commit). */
        $this->realtime->branchConfigurationChanged($destination, 'setup_copied');
    }
}
