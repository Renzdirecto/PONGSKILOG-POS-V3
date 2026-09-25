<?php

namespace Database\Seeders;

use App\Actions\Catalog\SyncProductModifierGroups;
use App\Actions\Catalog\UpsertBranchProduct;
use App\Actions\Operations\AdjustIngredientStock;
use App\Actions\Operations\SaveIngredient;
use App\Actions\Operations\SaveModifierEffect;
use App\Actions\Operations\SaveOperationPlan;
use App\Actions\Operations\SaveRecipe;
use App\Actions\Operations\SetIngredientArchived;
use App\Enums\ModifierSelectionType;
use App\Enums\ModifierSemanticRole;
use App\Models\Branch;
use App\Models\BranchIngredientStock;
use App\Models\BranchProduct;
use App\Models\Ingredient;
use App\Models\ModifierGroup;
use App\Models\OperationPlan;
use App\Models\OperationPlanIngredient;
use App\Models\OperationPlanProduct;
use App\Models\Product;
use App\Models\User;
use App\Support\ActiveBranchContext;
use App\Support\CatalogRealtime;
use App\Support\ExactQuantity;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * LOCAL QA / DEVELOPMENT ONLY (`php artisan operations:seed-qa`). Never part of DatabaseSeeder.
 *
 * Ensures the Phase 16E Owner Operations manual-QA dataset on top of LocalDevelopmentSeeder: the four Lemon drinks
 * (Product stock tracking off, in the MAIN and QAVE assortments), one Size group (Small / Medium / Large), a "Drink
 * Add-ons" Add-on / Modifier group (Nata, Extra Yakult) and a price-neutral "Instructions" group, and — separately for
 * MAIN and for QAVE, since Operations setup belongs to one Branch — the Drinks Plan, ten Ingredients, a base recipe per
 * Size, the Add-on Ingredient effects and different MAIN / QAVE stock.
 *
 * Idempotent and non-destructive: everything is found or created through the production actions; existing prices,
 * groups and assignments are kept. An existing Ingredient is reused only when its base unit matches (a locked,
 * different unit keeps the owner's record and uses the QA fallback name instead). Stock is brought to the QA starting
 * balance with the canonical audited count correction, so reruns reset stock without repeating opening balances.
 */
class LocalOperationsQaSeeder extends Seeder
{
    public const PRODUCTS = ['Lemon Calamansi', 'Lemon Cola', 'Lemon Pure', 'Lemon Yakult'];

    /**
     * key => [name, fallback name when the name exists with another locked unit, base unit, icon, target, purchase
     * unit name, purchase unit size, purchase unit cost, rule, reorder point, MAIN stock, QAVE stock]
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: string, 7: string, 8: string, 9: string|null, 10: string, 11: string}>
     */
    public const INGREDIENTS = [
        'lemon' => ['Lemon', 'Lemon (QA)', 'pc', 'lemon', '30', 'pc', '1', '10.00', 'top_up', null, '30', '18'],
        'yakult' => ['Yakult', 'Yakult Bottle', 'pc', 'bottle', '24', 'Pack of 5', '5', '55.00', 'reorder', '10', '24', '12'],
        'calamansi' => ['Calamansi', 'Calamansi Juice', 'ml', 'drop', '2000', 'Liter bottle', '1000', '120.00', 'top_up', null, '2000', '1200'],
        'cola' => ['Cola Syrup', 'Cola Syrup (QA)', 'ml', 'drop', '1500', 'Liter bottle', '1000', '180.00', 'top_up', null, '1500', '1000'],
        'sugar' => ['Sugar Syrup', 'Sugar Syrup (QA)', 'ml', 'drop', '2000', 'Liter bottle', '1000', '90.00', 'top_up', null, '2000', '1000'],
        'water' => ['Purified Water', 'Purified Water (QA)', 'ml', 'drop', '10000', 'Gallon', '5000', '40.00', 'top_up', null, '10000', '7000'],
        'nata' => ['Nata', 'Nata (QA)', 'g', 'bowl', '1000', 'Kilo pack', '1000', '200.00', 'top_up', null, '1000', '500'],
        'small_cup' => ['Small Cup', 'Small Cup (QA)', 'pc', 'cup', '20', 'Sleeve of 50', '50', '75.00', 'reorder', '10', '20', '12'],
        'medium_cup' => ['Medium Cup', 'Medium Cup (QA)', 'pc', 'cup', '15', 'Sleeve of 50', '50', '90.00', 'reorder', '8', '15', '8'],
        'large_cup' => ['Large Cup', 'Large Cup (QA)', 'pc', 'cup', '10', 'Sleeve of 50', '50', '110.00', 'reorder', '5', '10', '5'],
    ];

    /** @var array<string, array<string, array<string, string>>> Product => Size => Ingredient key => quantity */
    public const RECIPES = [
        'Lemon Yakult' => [
            'Small' => ['lemon' => '0.5', 'yakult' => '1', 'sugar' => '20', 'water' => '180', 'small_cup' => '1'],
            'Medium' => ['lemon' => '0.5', 'yakult' => '1', 'sugar' => '30', 'water' => '250', 'medium_cup' => '1'],
            'Large' => ['lemon' => '1', 'yakult' => '2', 'sugar' => '40', 'water' => '350', 'large_cup' => '1'],
        ],
        'Lemon Calamansi' => [
            'Small' => ['lemon' => '0.5', 'calamansi' => '25', 'sugar' => '20', 'water' => '180', 'small_cup' => '1'],
            'Medium' => ['lemon' => '0.5', 'calamansi' => '35', 'sugar' => '30', 'water' => '250', 'medium_cup' => '1'],
            'Large' => ['lemon' => '1', 'calamansi' => '45', 'sugar' => '40', 'water' => '350', 'large_cup' => '1'],
        ],
        'Lemon Cola' => [
            'Small' => ['lemon' => '0.5', 'cola' => '25', 'water' => '180', 'small_cup' => '1'],
            'Medium' => ['lemon' => '0.5', 'cola' => '35', 'water' => '250', 'medium_cup' => '1'],
            'Large' => ['lemon' => '1', 'cola' => '45', 'water' => '350', 'large_cup' => '1'],
        ],
        'Lemon Pure' => [
            'Small' => ['lemon' => '0.5', 'sugar' => '15', 'water' => '200', 'small_cup' => '1'],
            'Medium' => ['lemon' => '0.5', 'sugar' => '25', 'water' => '280', 'medium_cup' => '1'],
            'Large' => ['lemon' => '1', 'sugar' => '35', 'water' => '380', 'large_cup' => '1'],
        ],
    ];

    /** @var array<string, array<string, string>> Add-on option => Ingredient key => quantity per selection */
    public const EFFECTS = [
        'Extra Yakult' => ['yakult' => '1'],
        'Nata' => ['nata' => '30'],
    ];

    private const SIZES = ['Small' => '0.00', 'Medium' => '10.00', 'Large' => '20.00'];

    /** @var array<string, Ingredient> */
    private array $ingredients = [];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Operations QA data may only be seeded locally or in tests.');
        }
        $owner = User::query()->where('email', 'owner@gmail.com')->first()
            ?? throw new RuntimeException('Run LocalDevelopmentSeeder first: the owner@gmail.com QA account is missing.');
        $branches = Branch::query()->whereIn('code', ['MAIN', 'QAVE'])->get()->keyBy('code');
        if ($branches->count() !== 2) {
            throw new RuntimeException('Run LocalDevelopmentSeeder first: MAIN and QAVE branches are required.');
        }
        $products = Product::query()->whereIn('name', self::PRODUCTS)->get()->keyBy('name');
        if ($products->count() !== count(self::PRODUCTS)) {
            throw new RuntimeException('Run LocalDevelopmentSeeder first: missing '.implode(', ', array_diff(self::PRODUCTS, $products->keys()->all())).'.');
        }

        DB::transaction(function () use ($owner, $branches, $products): void {
            $this->withBranch(null);
            [$size, $addOns, $instructions] = $this->groups();
            foreach ($products as $product) {
                foreach ($branches as $branch) {
                    $configuration = BranchProduct::query()->where('branch_id', $branch->id)->where('product_id', $product->id)->first();
                    app(UpsertBranchProduct::class)->execute($owner, $branch, $product, [
                        'price_override' => $configuration?->price_override,
                        'is_available' => true,
                        'tracks_inventory' => false,
                        'low_stock_threshold' => $configuration?->low_stock_threshold,
                    ]);
                }
                app(SyncProductModifierGroups::class)->execute($owner, $product, array_values(array_unique([
                    ...$product->modifierGroups()->pluck('modifier_groups.id')->all(), $size->id, $addOns->id, $instructions->id,
                ])));
            }

            $sizeOptions = $size->options()->pluck('id', 'name');
            foreach ($branches as $code => $branch) {
                $this->withBranch($branch);
                $plan = $this->plan($owner, $branch, $products->map(fn (Product $product): string => $product->id)->values()->all());
                $this->ingredients = [];
                foreach (self::INGREDIENTS as $key => $definition) {
                    $this->ingredients[$key] = $this->ingredient($owner, $branch, $plan, $definition);
                    $this->stock($owner, $branch, $this->ingredients[$key], $definition[$code === 'MAIN' ? 10 : 11]);
                }
                foreach (self::RECIPES as $productName => $recipes) {
                    foreach ($recipes as $sizeName => $lines) {
                        app(SaveRecipe::class)->execute($owner, $products[$productName], [
                            'size_option_id' => $sizeOptions[$sizeName],
                            'lines' => $this->lines($lines),
                        ]);
                    }
                    foreach (self::EFFECTS as $optionName => $lines) {
                        app(SaveModifierEffect::class)->execute($owner, $products[$productName], $addOns->options()->where('name', $optionName)->sole(), [
                            'lines' => $this->lines($lines),
                        ]);
                    }
                }
            }
            $this->withBranch(null);
        });

        foreach ($branches as $branch) {
            app(CatalogRealtime::class)->ingredientsChanged($branch, 'recipe_changed');
        }
    }

    /** @return array{ModifierGroup, ModifierGroup, ModifierGroup} */
    private function groups(): array
    {
        $size = ModifierGroup::query()->where('semantic_role', ModifierSemanticRole::Size->value)->where('is_active', true)->where('name', 'Size')->first()
            ?? ModifierGroup::query()->create([
                'name' => 'Size', 'semantic_role' => ModifierSemanticRole::Size, 'selection_type' => ModifierSelectionType::Single,
                'min_select' => 1, 'max_select' => 1, 'is_active' => true,
            ]);
        $sort = 0;
        foreach (self::SIZES as $name => $price) {
            $option = $size->options()->firstOrCreate(['name' => $name], ['price_delta' => $price, 'is_active' => true, 'sort_order' => $sort]);
            /** Keeps prices; only ensures the option is active and ordered Small → Medium → Large. */
            if (! $option->is_active || $option->sort_order !== $sort) {
                $option->update(['is_active' => true, 'sort_order' => $sort]);
            }
            $sort++;
        }

        $addOns = ModifierGroup::query()->firstOrCreate(['name' => 'Drink Add-ons'], [
            'semantic_role' => null, 'selection_type' => ModifierSelectionType::Multiple, 'min_select' => 0, 'max_select' => 2, 'is_active' => true,
        ]);
        foreach (['Nata' => '15.00', 'Extra Yakult' => '15.00'] as $name => $price) {
            $addOns->options()->firstOrCreate(['name' => $name], ['price_delta' => $price, 'is_active' => true, 'sort_order' => $sort++]);
        }

        $instructions = ModifierGroup::query()->firstOrCreate(['name' => 'Instructions'], [
            'semantic_role' => ModifierSemanticRole::Instruction, 'selection_type' => ModifierSelectionType::Multiple,
            'min_select' => 0, 'max_select' => 2, 'is_active' => true,
        ]);
        foreach (['No Ice', 'Less Sugar'] as $index => $name) {
            /** Instructions are always price-neutral. */
            $instructions->options()->firstOrCreate(['name' => $name], ['price_delta' => '0.00', 'is_active' => true, 'sort_order' => $index]);
        }

        return [$size, $addOns, $instructions];
    }

    /** @param array<int, string> $productIds */
    private function plan(User $owner, Branch $branch, array $productIds): OperationPlan
    {
        $plan = OperationPlan::query()->where('branch_id', $branch->id)->whereNull('archived_at')->whereRaw('LOWER(name) = ?', ['drinks'])->first();
        $members = $plan === null ? [] : OperationPlanProduct::query()->where('operation_plan_id', $plan->id)->pluck('product_id')->all();
        if ($plan !== null && array_diff($productIds, $members) === []) {
            return $plan;
        }

        return app(SaveOperationPlan::class)->execute($owner, $plan, [
            'name' => $plan->name ?? 'Drinks',
            'description' => $plan?->description,
            'icon' => $plan->icon ?? 'glass',
            'product_ids' => array_values(array_unique([...$members, ...$productIds])),
        ]);
    }

    /** @param array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: string, 7: string, 8: string, 9: string|null, 10: string, 11: string} $definition */
    private function ingredient(User $owner, Branch $branch, OperationPlan $plan, array $definition): Ingredient
    {
        [$name, $fallback, $unit, $icon, $target, $purchaseUnit, $purchaseSize, $cost, $rule, $reorder] = $definition;
        $ingredient = null;
        foreach ([$name, $fallback] as $candidate) {
            $existing = Ingredient::query()->where('branch_id', $branch->id)->where('name', $candidate)->first();
            if ($existing === null || $existing->base_unit === $unit) {
                $ingredient = $existing;
                $name = $candidate;
                break;
            }
        }
        if ($ingredient === null && Ingredient::query()->where('branch_id', $branch->id)->where('name', $name)->exists()) {
            throw new RuntimeException("{$definition[0]} and {$fallback} both exist with a unit other than {$unit}.");
        }
        $ingredient ??= app(SaveIngredient::class)->execute($owner, null, [
            'name' => $name, 'icon' => $icon, 'base_unit' => $unit, 'target_quantity' => $target,
            'purchase_unit_name' => $purchaseUnit, 'purchase_unit_size' => $purchaseSize, 'purchase_unit_cost' => $cost,
            'replenishment_rule' => $rule, 'reorder_point' => $reorder, 'plan_ids' => [$plan->id], 'initial_quantity' => null,
        ]);
        if ($ingredient->archived_at !== null) {
            $ingredient = app(SetIngredientArchived::class)->execute($owner, $ingredient, false);
        }
        OperationPlanIngredient::query()->firstOrCreate(['operation_plan_id' => $plan->id, 'ingredient_id' => $ingredient->id], ['branch_id' => $branch->id]);

        return $ingredient;
    }

    /** Brings the Branch balance to the QA starting quantity through the audited count correction (no-op when equal). */
    private function stock(User $owner, Branch $branch, Ingredient $ingredient, string $quantity): void
    {
        $current = ExactQuantity::parse(BranchIngredientStock::query()->where('branch_id', $branch->id)->where('ingredient_id', $ingredient->id)->value('on_hand'));
        if ($current === ExactQuantity::fromInput($quantity)) {
            return;
        }
        app(AdjustIngredientStock::class)->execute($owner, $ingredient, [
            'mode' => 'count', 'quantity' => $quantity, 'reason' => 'Opening count', 'note' => 'Local QA starting stock',
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    /**
     * @param  array<string, string>  $lines
     * @return list<array{ingredient_id: string, quantity: string}>
     */
    private function lines(array $lines): array
    {
        return array_map(fn (string $key, string $quantity): array => ['ingredient_id' => $this->ingredients[$key]->id, 'quantity' => $quantity], array_keys($lines), $lines);
    }

    private function withBranch(?Branch $branch): void
    {
        session([ActiveBranchContext::SESSION_KEY => $branch?->id]);
    }
}
