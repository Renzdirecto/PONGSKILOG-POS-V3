<?php

namespace App\Actions\Operations;

use App\Enums\IngredientMovementType;
use App\Enums\ModifierSemanticRole;
use App\Enums\RecipeState;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\IngredientMovement;
use App\Models\OperationPlanProduct;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderRecipeSnapshot;
use App\Models\OrderRecipeSnapshotLine;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\User;
use App\Support\ExactMoney;
use App\Support\ExactQuantity;
use Illuminate\Support\Collection;

/**
 * Ingredient consumption of committed Orders, integrated into the canonical order lifecycle:
 *
 * - commit(): Pay Now and Pay Later call it once, inside their commit transaction, through ApplyOrderInventory.
 * - edit(): a committed edit appends only the compensating delta between recorded and newly required consumption.
 * - void(): restores exactly the current net recorded consumption, once.
 *
 * Every Order Product/size is snapshotted when first committed (recipe, cost basis and Plan). Edits and Voids always
 * work from that snapshot and the recorded movements, never from today's recipe, costs or Plan membership.
 *
 * @phpstan-type UsageChange array{ingredient_id: string, product_id: string, size_key: string, quantity_delta: string, movement_id: string}
 * @phpstan-type Pair array{product_id: string, size_option_id: string|null, size_key: string, size_name: string|null, product_name: string, units: int}
 */
class RecordOrderIngredientUsage
{
    private const ORDER_TYPES = [
        IngredientMovementType::SaleConsumption,
        IngredientMovementType::OrderEditAdjustment,
        IngredientMovementType::VoidRestoration,
    ];

    public function __construct(private ApplyIngredientMovement $movements) {}

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
        $snapshots = $first ? collect() : OrderRecipeSnapshot::query()->where('order_id', $order->id)->with('lines')->get()
            ->keyBy(fn (OrderRecipeSnapshot $snapshot): string => $snapshot->product_id.'|'.$snapshot->size_key);
        $missing = array_diff_key($pairs, $snapshots->all());
        if ($missing !== []) {
            foreach ($this->createSnapshots($order, $branch, array_values($missing), $products) as $key => $snapshot) {
                $snapshots->put($key, $snapshot);
            }
        }

        $recorded = $first ? [] : $this->recorded($order);
        $planned = [];
        foreach ($snapshots as $key => $snapshot) {
            if ($snapshot->recipe_state !== RecipeState::Recipe) {
                continue;
            }
            $units = $pairs[$key]['units'] ?? 0;
            foreach ($snapshot->lines as $line) {
                $required = ExactQuantity::times(ExactQuantity::parse($line->quantity_per_unit), $units);
                $requiredCost = $this->cost($line, $required);
                $usage = $recorded[$snapshot->id][$line->ingredient_id] ?? ['consumed' => 0, 'cost' => 0, 'movements' => 0];
                $delta = $usage['consumed'] - $required;
                if ($delta === 0) {
                    continue;
                }
                $planned[] = [
                    'snapshot' => $snapshot,
                    'ingredient_id' => $line->ingredient_id,
                    'delta' => $delta,
                    'cost' => $requiredCost === null || $usage['cost'] === null ? null : $requiredCost - $usage['cost'],
                ];
            }
        }

        return $this->apply($order, $branch, $actor, $type, $planned);
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
        $reason = match ($type) {
            IngredientMovementType::SaleConsumption => 'Sale',
            IngredientMovementType::OrderEditAdjustment => 'Committed order edit',
            default => 'Void',
        }.' · order '.($order->order_number ?? $order->reference_number ?? $order->id);

        return array_map(function (array $change) use ($order, $branch, $actor, $type, $reason, $stocks): array {
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

    private function cost(OrderRecipeSnapshotLine $line, int $quantity): ?int
    {
        if ($line->cost_basis_cents === null || $line->cost_basis_quantity === null) {
            return null;
        }

        return ExactQuantity::costCents($quantity, $line->cost_basis_cents, ExactQuantity::parse($line->cost_basis_quantity));
    }

    /**
     * Committed units per Product/size from the Order's current items.
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
            ];
            $pairs[$key]['units'] += $item->quantity;
        });

        return $pairs;
    }

    /**
     * Snapshots the recipe state, recipe lines, cost basis and Plan in force now for Product/size pairs committed for
     * the first time. A Product that tracks Product stock at this Branch never also consumes Ingredients.
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

        $created = [];
        foreach ($pairs as $pair) {
            $key = $pair['product_id'].'|'.$pair['size_key'];
            $recipe = $recipes->get($key);
            $state = match (true) {
                $tracked->has($pair['product_id']), (bool) $products->get($pair['product_id'])?->no_recipe_needed => RecipeState::NotNeeded,
                $recipe?->lines->isNotEmpty() === true => RecipeState::Recipe,
                default => RecipeState::Missing,
            };
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
                    $ingredient = $line->ingredient;
                    $known = $ingredient->purchase_unit_cost !== null && $ingredient->purchase_unit_size !== null
                        && ExactQuantity::parse($ingredient->purchase_unit_size) > 0;
                    $lines[] = $snapshot->lines()->create([
                        'ingredient_id' => $line->ingredient_id,
                        'quantity_per_unit' => ExactQuantity::decimal(ExactQuantity::parse($line->quantity)),
                        'cost_basis_cents' => $known ? ExactMoney::cents((string) $ingredient->purchase_unit_cost) : null,
                        'cost_basis_quantity' => $known ? ExactQuantity::decimal(ExactQuantity::parse($ingredient->purchase_unit_size)) : null,
                    ]);
                }
            }
            $created[$key] = $snapshot->setRelation('lines', $snapshot->lines()->getRelated()->newCollection($lines));
        }

        return $created;
    }
}
