<?php

namespace App\Actions\Operations;

use App\Enums\IngredientMovementType;
use App\Enums\ModifierSemanticRole;
use App\Enums\RecipeState;
use App\Models\Branch;
use App\Models\BranchIngredientStock;
use App\Models\BranchProduct;
use App\Models\Ingredient;
use App\Models\IngredientMovement;
use App\Models\OperationPlanProduct;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderRecipeSnapshot;
use App\Models\OrderRecipeSnapshotLine;
use App\Models\OrderRecipeSnapshotModifier;
use App\Models\OrderRecipeSnapshotModifierLine;
use App\Models\Product;
use App\Models\ProductModifierEffect;
use App\Models\Recipe;
use App\Models\User;
use App\Support\CatalogRealtime;
use App\Support\ExactMoney;
use App\Support\ExactQuantity;
use App\Support\ProductSizes;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Ingredient consumption of committed Orders, integrated into the canonical order lifecycle:
 *
 * - commit(): Pay Now and Pay Later call it once, inside their commit transaction, through ApplyOrderInventory.
 * - edit(): a committed edit appends only the compensating delta between recorded and newly required consumption.
 * - void(): restores exactly the current net recorded consumption, once.
 *
 * A sale needs the base recipe of each Product/size plus the Product-specific effect of each selected Add-on /
 * Modifier (Instructions never move stock). Every Order Product/size is snapshotted when first committed (recipe,
 * cost basis and Plan), and every Add-on when first committed on it. Edits and Voids always work from those snapshots
 * and the recorded movements, never from today's recipe, Add-on effects, costs or Plan membership.
 *
 * A sale or a usage-increasing edit must not drive a required Ingredient below zero: the whole order's net delta per
 * Ingredient is checked under the locked balances, so one of two racing sales for the last stock is rejected cleanly.
 * Restorations (voids and usage-decreasing edits) are always allowed.
 *
 * @phpstan-type UsageChange array{ingredient_id: string, product_id: string, size_key: string, quantity_delta: string, movement_id: string}
 * @phpstan-type AddOn array{selections: int, option_name: string, group_name: string|null}
 * @phpstan-type Pair array{product_id: string, size_option_id: string|null, size_key: string, size_name: string|null, product_name: string, units: int, add_ons: array<string, AddOn>}
 */
class RecordOrderIngredientUsage
{
    private const ORDER_TYPES = [
        IngredientMovementType::SaleConsumption,
        IngredientMovementType::OrderEditAdjustment,
        IngredientMovementType::VoidRestoration,
    ];

    public function __construct(
        private ApplyIngredientMovement $movements,
        private ProductSizes $sizes,
        private CatalogRealtime $realtime,
    ) {}

    /**
     * @param  Collection<string, Product>|null  $products  the Order's Products with this Branch's configuration, as
     *                                                      ApplyOrderInventory already loaded and locked them
     * @return list<UsageChange>
     */
    public function commit(Order $order, Branch $branch, User $actor, ?Collection $products = null): array
    {
        return $this->reconcile($order, $branch, $actor, IngredientMovementType::SaleConsumption, $products);
    }

    /** @return list<UsageChange> */
    public function edit(Order $order, Branch $branch, User $actor): array
    {
        return $this->reconcile($order, $branch, $actor, IngredientMovementType::OrderEditAdjustment);
    }

    /** @return list<UsageChange> */
    public function void(Order $order, Branch $branch, User $actor): array
    {
        $snapshots = OrderRecipeSnapshot::query()->where('order_id', $order->id)->get()->keyBy('id');
        $recorded = $this->recorded($order);
        $planned = [];
        foreach ($recorded as $snapshotId => $ingredients) {
            foreach ($ingredients as $ingredientId => $usage) {
                if ($usage['consumed'] !== 0) {
                    $planned[] = [
                        'snapshot' => $snapshots->get($snapshotId) ?? throw new \LogicException('Ingredient usage references a missing snapshot.'),
                        'ingredient_id' => $ingredientId,
                        'delta' => $usage['consumed'],
                        'cost' => $usage['cost'] === null ? null : -$usage['cost'],
                    ];
                }
            }
        }

        return $this->apply($order, $branch, $actor, IngredientMovementType::VoidRestoration, $planned);
    }

    /**
     * @param  Collection<string, Product>|null  $products
     * @return list<UsageChange>
     */
    private function reconcile(Order $order, Branch $branch, User $actor, IngredientMovementType $type, ?Collection $products = null): array
    {
        /** A first commitment has no snapshots or usage yet; the order row lock and committed_at make it run once. */
        $first = $type === IngredientMovementType::SaleConsumption;
        $pairs = $this->pairs($order, useLoadedItems: $first);
        $snapshots = $first ? collect() : OrderRecipeSnapshot::query()->where('order_id', $order->id)->with('lines', 'modifiers.lines')->get()
            ->keyBy(fn (OrderRecipeSnapshot $snapshot): string => $snapshot->product_id.'|'.$snapshot->size_key);
        $missing = array_diff_key($pairs, $snapshots->all());
        if ($missing !== []) {
            foreach ($this->createSnapshots($order, $branch, array_values($missing), $products) as $key => $snapshot) {
                $snapshots->put($key, $snapshot);
            }
        }
        $this->snapshotAddOns($snapshots, $pairs);

        $recorded = $first ? [] : $this->recorded($order);
        $planned = [];
        foreach ($snapshots as $key => $snapshot) {
            if ($snapshot->recipe_state !== RecipeState::Recipe) {
                continue;
            }
            $pair = $pairs[$key] ?? null;
            /** @var array<string, int> $required */
            $required = [];
            /** @var array<string, int|null> $requiredCost */
            $requiredCost = [];
            foreach ($snapshot->lines as $line) {
                $this->require($required, $requiredCost, $line->ingredient_id, ExactQuantity::times(ExactQuantity::parse($line->quantity_per_unit), $pair['units'] ?? 0), $line);
            }
            foreach ($snapshot->modifiers as $modifier) {
                $selections = $pair['add_ons'][$modifier->modifier_option_id]['selections'] ?? 0;
                foreach ($selections === 0 ? [] : $modifier->lines as $line) {
                    $this->require($required, $requiredCost, $line->ingredient_id, ExactQuantity::times(ExactQuantity::parse($line->quantity_per_selection), $selections), $line);
                }
            }

            /** Ingredients no longer required (a removed Add-on or item) are restored from what was recorded. */
            $ingredientIds = array_values(array_unique([...array_keys($required), ...array_keys($recorded[$snapshot->id] ?? [])]));
            sort($ingredientIds);
            foreach ($ingredientIds as $ingredientId) {
                $usage = $recorded[$snapshot->id][$ingredientId] ?? ['consumed' => 0, 'cost' => 0, 'movements' => 0];
                $delta = $usage['consumed'] - ($required[$ingredientId] ?? 0);
                if ($delta === 0) {
                    continue;
                }
                /** An unknown cost basis stays null (never ₱0); an Ingredient no longer required costs nothing. */
                $cost = array_key_exists($ingredientId, $requiredCost) ? $requiredCost[$ingredientId] : 0;
                $planned[] = [
                    'snapshot' => $snapshot,
                    'ingredient_id' => $ingredientId,
                    'delta' => $delta,
                    'cost' => $cost === null || $usage['cost'] === null ? null : $cost - $usage['cost'],
                ];
            }
        }

        return $this->apply($order, $branch, $actor, $type, $planned);
    }

    /**
     * @param  array<string, int>  $required
     * @param  array<string, int|null>  $requiredCost
     */
    private function require(array &$required, array &$requiredCost, string $ingredientId, int $quantity, OrderRecipeSnapshotLine|OrderRecipeSnapshotModifierLine $line): void
    {
        if ($quantity === 0) {
            return;
        }
        $required[$ingredientId] = ExactQuantity::add($required[$ingredientId] ?? 0, $quantity);
        $cost = $line->cost_basis_cents === null || $line->cost_basis_quantity === null ? null
            : ExactQuantity::costCents($quantity, $line->cost_basis_cents, ExactQuantity::parse($line->cost_basis_quantity));
        $current = array_key_exists($ingredientId, $requiredCost) ? $requiredCost[$ingredientId] : 0;
        $requiredCost[$ingredientId] = $current === null || $cost === null ? null : $current + $cost;
    }

    /**
     * @param  list<array{snapshot: OrderRecipeSnapshot, ingredient_id: string, delta: int, cost: int|null}>  $planned
     * @return list<UsageChange>
     */
    private function apply(Order $order, Branch $branch, User $actor, IngredientMovementType $type, array $planned): array
    {
        if ($planned === []) {
            return [];
        }
        usort($planned, fn (array $left, array $right): int => [$left['ingredient_id'], $left['snapshot']->id] <=> [$right['ingredient_id'], $right['snapshot']->id]);
        $stocks = $this->movements->lock($branch, array_column($planned, 'ingredient_id'));
        if ($type !== IngredientMovementType::VoidRestoration) {
            $this->assertStockCovers($stocks, $planned);
        }
        $reason = match ($type) {
            IngredientMovementType::SaleConsumption => 'Sale',
            IngredientMovementType::OrderEditAdjustment => 'Committed order edit',
            default => 'Void',
        }.' · order '.($order->order_number ?? $order->reference_number ?? $order->id);

        $changes = array_map(function (array $change) use ($order, $branch, $actor, $type, $reason, $stocks): array {
            $snapshot = $change['snapshot'];
            $movement = $this->movements->execute($branch, $change['ingredient_id'], $type, $change['delta'], [
                'order_id' => $order->id,
                'order_recipe_snapshot_id' => $snapshot->id,
                'operation_plan_id' => $snapshot->operation_plan_id,
                'estimated_cost_cents' => $change['cost'],
                'reason' => $reason,
                'created_by_user_id' => $actor->id,
            ], $stocks->get($change['ingredient_id']));

            return [
                'ingredient_id' => $change['ingredient_id'],
                'product_id' => $snapshot->product_id,
                'size_key' => $snapshot->size_key,
                'quantity_delta' => ExactQuantity::display($change['delta']),
                'movement_id' => $movement->id,
            ];
        }, $planned);
        $this->realtime->ingredientsChanged($branch, match ($type) {
            IngredientMovementType::SaleConsumption => 'sale',
            IngredientMovementType::OrderEditAdjustment => 'order_edit',
            default => 'void',
        });

        return $changes;
    }

    /**
     * A sale or usage-increasing edit must leave every required Ingredient at zero or above. The check uses the whole
     * order's net delta per Ingredient against the locked balance, so only additional usage needs stock and an
     * existing negative balance (from a manual correction) can never be driven further down by a sale.
     *
     * @param  Collection<string, BranchIngredientStock>  $stocks
     * @param  list<array{snapshot: OrderRecipeSnapshot, ingredient_id: string, delta: int, cost: int|null}>  $planned
     */
    private function assertStockCovers(Collection $stocks, array $planned): void
    {
        $net = [];
        foreach ($planned as $change) {
            $net[$change['ingredient_id']] = ($net[$change['ingredient_id']] ?? 0) + $change['delta'];
        }
        $short = [];
        foreach ($net as $ingredientId => $delta) {
            $onHand = ExactQuantity::parse($stocks->get($ingredientId)?->on_hand);
            if ($delta < 0 && $onHand + $delta < 0) {
                $short[$ingredientId] = ['needed' => -$delta, 'left' => max($onHand, 0)];
            }
        }
        if ($short === []) {
            return;
        }
        $ingredients = Ingredient::query()->whereKey(array_keys($short))->orderBy('name')->get(['id', 'name', 'base_unit']);
        $details = $ingredients->map(fn (Ingredient $ingredient): string => $ingredient->name.' needs '
            .ExactQuantity::display($short[$ingredient->id]['needed']).' '.$ingredient->base_unit.', '
            .ExactQuantity::display($short[$ingredient->id]['left']).' '.$ingredient->base_unit.' left')->implode('; ');

        throw ValidationException::withMessages([
            'items' => 'Not enough ingredient stock for this order ('.$details.'). Reduce the quantity, remove an item, or restock first.',
        ]);
    }

    /**
     * Net recorded consumption per snapshot and Ingredient (positive = consumed), with its net estimated cost or null
     * when the snapshot's cost basis was unknown.
     *
     * @return array<string, array<string, array{consumed: int, cost: int|null, movements: int}>>
     */
    private function recorded(Order $order): array
    {
        $recorded = [];
        IngredientMovement::query()
            ->where('order_id', $order->id)
            ->whereIn('movement_type', array_map(fn (IngredientMovementType $type): string => $type->value, self::ORDER_TYPES))
            ->whereNotNull('order_recipe_snapshot_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['order_recipe_snapshot_id', 'ingredient_id', 'quantity_delta', 'estimated_cost_cents'])
            ->each(function (IngredientMovement $movement) use (&$recorded): void {
                $usage = $recorded[$movement->order_recipe_snapshot_id][$movement->ingredient_id] ?? ['consumed' => 0, 'cost' => 0, 'movements' => 0];
                $usage['consumed'] -= ExactQuantity::parse($movement->quantity_delta);
                $usage['cost'] = $usage['cost'] === null || $movement->estimated_cost_cents === null ? null : $usage['cost'] + $movement->estimated_cost_cents;
                $usage['movements']++;
                $recorded[(string) $movement->order_recipe_snapshot_id][$movement->ingredient_id] = $usage;
            });

        return $recorded;
    }

    /**
     * Committed units per Product/size from the Order's current items, with Add-on selections (item quantity × the
     * OrderItemModifier quantity). Instruction and Size selections are never Add-ons.
     *
     * @return array<string, Pair>
     */
    private function pairs(Order $order, bool $useLoadedItems = false): array
    {
        $pairs = [];
        /** Only a first commitment may reuse loaded items; an edit has just replaced them in the database. */
        $items = $useLoadedItems && $order->relationLoaded('items')
            ? $order->items->loadMissing('modifiers')
            : OrderItem::query()->where('order_id', $order->id)->with('modifiers')->orderBy('id')->get();
        $items->each(function (OrderItem $item) use (&$pairs): void {
            if ($item->product_id === null) {
                return;
            }
            $size = $item->modifiers->first(fn ($modifier): bool => $modifier->semantic_role_snapshot === ModifierSemanticRole::Size->value
                && $modifier->modifier_option_id !== null);
            $sizeKey = Recipe::sizeKey($size?->modifier_option_id);
            $key = $item->product_id.'|'.$sizeKey;
            $pairs[$key] ??= [
                'product_id' => $item->product_id,
                'size_option_id' => $size?->modifier_option_id,
                'size_key' => $sizeKey,
                'size_name' => $size?->option_name_snapshot,
                'product_name' => $item->product_name_snapshot,
                'units' => 0,
                'add_ons' => [],
            ];
            $pairs[$key]['units'] += $item->quantity;
            foreach ($item->modifiers as $modifier) {
                if ($modifier->semantic_role_snapshot !== null || $modifier->modifier_option_id === null) {
                    continue;
                }
                $addOn = $pairs[$key]['add_ons'][$modifier->modifier_option_id] ?? [
                    'selections' => 0,
                    'option_name' => $modifier->option_name_snapshot,
                    'group_name' => $modifier->group_name_snapshot,
                ];
                $addOn['selections'] += $item->quantity * max(1, (int) $modifier->quantity);
                $pairs[$key]['add_ons'][$modifier->modifier_option_id] = $addOn;
            }
        });

        return $pairs;
    }

    /**
     * Snapshots the recipe state, recipe lines, cost basis and Plan in force now for Product/size pairs committed for
     * the first time. A Product that tracks Product stock at this Branch never also consumes Ingredients. A
     * Recipe-backed Product (it has a recipe for some size) cannot sell a size without a recipe, nor while it has more
     * than one Size group.
     *
     * @param  list<Pair>  $pairs
     * @param  Collection<string, Product>|null  $loaded
     * @return array<string, OrderRecipeSnapshot>
     */
    private function createSnapshots(Order $order, Branch $branch, array $pairs, ?Collection $loaded = null): array
    {
        $productIds = array_values(array_unique(array_column($pairs, 'product_id')));
        if ($loaded !== null && array_diff($productIds, $loaded->keys()->all()) === []) {
            $products = $loaded;
            $tracked = $loaded->filter(fn (Product $product): bool => (bool) $product->branchProducts->firstWhere('branch_id', $branch->id)?->tracks_inventory)
                ->keys()->flip();
        } else {
            $products = Product::query()->whereKey($productIds)->get(['id', 'no_recipe_needed'])->keyBy('id');
            $tracked = BranchProduct::query()->where('branch_id', $branch->id)->whereIn('product_id', $productIds)
                ->where('tracks_inventory', true)->pluck('product_id')->flip();
        }
        $plans = OperationPlanProduct::query()
            ->join('operation_plans', 'operation_plans.id', '=', 'operation_plan_products.operation_plan_id')
            ->whereNull('operation_plans.archived_at')
            ->whereIn('operation_plan_products.product_id', $productIds)
            ->pluck('operation_plan_products.operation_plan_id', 'operation_plan_products.product_id');
        /** @var Collection<string, Recipe> $recipes */
        $recipes = Recipe::query()->whereIn('product_id', $productIds)->with('lines.ingredient')->get()
            ->keyBy(fn (Recipe $recipe): string => $recipe->product_id.'|'.$recipe->size_key);
        $recipeBacked = $recipes->map(fn (Recipe $recipe): string => $recipe->product_id)->flip();
        $conflicts = $recipeBacked->isEmpty() ? [] : $this->sizes->conflicts($recipeBacked->keys()->all());

        $created = [];
        foreach ($pairs as $pair) {
            $key = $pair['product_id'].'|'.$pair['size_key'];
            $recipe = $recipes->get($key);
            $state = match (true) {
                $tracked->has($pair['product_id']), (bool) $products->get($pair['product_id'])?->no_recipe_needed => RecipeState::NotNeeded,
                $recipe?->lines->isNotEmpty() === true => RecipeState::Recipe,
                default => RecipeState::Missing,
            };
            if ($state !== RecipeState::NotNeeded && $recipeBacked->has($pair['product_id'])) {
                if (isset($conflicts[$pair['product_id']])) {
                    throw ValidationException::withMessages(['items' => ProductSizes::conflictMessage($pair['product_name'], $conflicts[$pair['product_id']])]);
                }
                if ($state === RecipeState::Missing) {
                    throw ValidationException::withMessages(['items' => ($pair['size_name'] === null ? '' : $pair['size_name'].' ').$pair['product_name']
                        .' needs a recipe before it can be sold. Set it up in Operations › Recipes or choose another size.']);
                }
            }
            $snapshot = OrderRecipeSnapshot::query()->create([
                'order_id' => $order->id,
                'branch_id' => $branch->id,
                'product_id' => $pair['product_id'],
                'size_modifier_option_id' => $pair['size_option_id'],
                'size_key' => $pair['size_key'],
                'product_name_snapshot' => $pair['product_name'],
                'size_name_snapshot' => $pair['size_name'],
                'recipe_state' => $state,
                'operation_plan_id' => $plans->get($pair['product_id']),
            ]);
            $lines = [];
            if ($state === RecipeState::Recipe) {
                foreach ($recipe->lines->sortBy('ingredient_id') as $line) {
                    $lines[] = $snapshot->lines()->create([
                        'ingredient_id' => $line->ingredient_id,
                        'quantity_per_unit' => ExactQuantity::decimal(ExactQuantity::parse($line->quantity)),
                        ...$this->costBasis($line->ingredient),
                    ]);
                }
            }
            $snapshot->setRelation('lines', $snapshot->lines()->getRelated()->newCollection($lines));
            $created[$key] = $snapshot->setRelation('modifiers', $snapshot->modifiers()->getRelated()->newCollection());
        }

        return $created;
    }

    /**
     * Snapshots the current Product-specific effect of each Add-on committed on a recipe snapshot for the first time.
     * Add-ons already snapshotted keep their historical effect, including "no Ingredient effect".
     *
     * @param  Collection<string, OrderRecipeSnapshot>  $snapshots
     * @param  array<string, Pair>  $pairs
     */
    private function snapshotAddOns(Collection $snapshots, array $pairs): void
    {
        $needed = [];
        foreach ($snapshots as $key => $snapshot) {
            if ($snapshot->recipe_state !== RecipeState::Recipe) {
                continue;
            }
            $known = $snapshot->modifiers->pluck('modifier_option_id')->flip();
            foreach ($pairs[$key]['add_ons'] ?? [] as $optionId => $addOn) {
                if (! $known->has($optionId)) {
                    $needed[$key][$optionId] = $addOn;
                }
            }
        }
        if ($needed === []) {
            return;
        }
        $effects = ProductModifierEffect::query()
            ->whereIn('product_id', array_values(array_unique(array_map(fn (string $key): string => $snapshots[$key]->product_id, array_keys($needed)))))
            ->whereIn('modifier_option_id', array_values(array_unique(array_merge(...array_map('array_keys', array_values($needed))))))
            ->with('lines.ingredient')->get()
            ->keyBy(fn (ProductModifierEffect $effect): string => $effect->product_id.'|'.$effect->modifier_option_id);

        foreach ($needed as $key => $addOns) {
            $snapshot = $snapshots[$key];
            ksort($addOns);
            $modifiers = $snapshot->modifiers;
            foreach ($addOns as $optionId => $addOn) {
                $modifier = OrderRecipeSnapshotModifier::query()->create([
                    'order_recipe_snapshot_id' => $snapshot->id,
                    'modifier_option_id' => $optionId,
                    'option_name_snapshot' => $addOn['option_name'],
                    'group_name_snapshot' => $addOn['group_name'],
                ]);
                $lines = [];
                foreach ($effects->get($snapshot->product_id.'|'.$optionId)?->lines->sortBy('ingredient_id') ?? [] as $line) {
                    $lines[] = $modifier->lines()->create([
                        'ingredient_id' => $line->ingredient_id,
                        'quantity_per_selection' => ExactQuantity::decimal(ExactQuantity::parse($line->quantity)),
                        ...$this->costBasis($line->ingredient),
                    ]);
                }
                $modifiers->push($modifier->setRelation('lines', $modifier->lines()->getRelated()->newCollection($lines)));
            }
            $snapshot->setRelation('modifiers', $modifiers);
        }
    }

    /** @return array{cost_basis_cents: int|null, cost_basis_quantity: string|null} */
    private function costBasis(Ingredient $ingredient): array
    {
        $known = $ingredient->purchase_unit_cost !== null && $ingredient->purchase_unit_size !== null
            && ExactQuantity::parse($ingredient->purchase_unit_size) > 0;

        return [
            'cost_basis_cents' => $known ? ExactMoney::cents((string) $ingredient->purchase_unit_cost) : null,
            'cost_basis_quantity' => $known ? ExactQuantity::decimal(ExactQuantity::parse($ingredient->purchase_unit_size)) : null,
        ];
    }
}
