<?php

namespace App\Support;

use App\Enums\ModifierSemanticRole;
use App\Enums\StoreSessionStatus;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Ingredient;
use App\Models\IngredientMovement;
use App\Models\OperationPlan;
use App\Models\OperationPlanProduct;
use App\Models\PamamalengkeListEntry;
use App\Models\PamamalengkePurchase;
use App\Models\PamamalengkePurchaseItem;
use App\Models\Product;
use App\Models\ProductModifierEffect;
use App\Models\ProductModifierEffectLine;
use App\Models\Recipe;
use App\Models\RecipeLine;
use App\Models\StoreSession;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only props for the Owner Operations pages. Every figure comes from a server-side authority:
 * IngredientStockReport (canonical Branch stock + ReplenishmentAdvisor), OperationsSummary (sales/COGS/profit on the
 * Phase 16 reporting authority) and the append-only movement and purchase records. React only formats these values.
 *
 * @phpstan-import-type IngredientRow from IngredientStockReport
 */
class OperationsWorkspace
{
    public function __construct(
        private IngredientStockReport $stock,
        private OperationsSummary $summary,
        private ProductSizes $sizes,
        private ProductImages $images,
        private RecipeCapacity $capacity,
    ) {}

    /** @return EloquentCollection<int, OperationPlan> */
    public function activePlans(): EloquentCollection
    {
        return OperationPlan::query()->whereNull('archived_at')->orderBy('name')->orderBy('id')->get();
    }

    /** @param EloquentCollection<int, OperationPlan> $plans */
    public function resolvePlan(EloquentCollection $plans, ?string $requested): ?OperationPlan
    {
        return $plans->firstWhere('id', is_string($requested) ? strtolower($requested) : null) ?? $plans->first();
    }

    /**
     * Shared context of every Operations page: Branch scope, active Plans and the URL-addressable active Plan.
     *
     * @param  EloquentCollection<int, OperationPlan>  $plans
     * @return array<string, mixed>
     */
    public function context(string $page, ?Branch $branch, EloquentCollection $plans, ?OperationPlan $active): array
    {
        $productCounts = OperationPlanProduct::query()->whereIn('operation_plan_id', $plans->modelKeys())
            ->groupBy('operation_plan_id')->selectRaw('operation_plan_id, COUNT(*) AS total')->pluck('total', 'operation_plan_id');
        $ingredientCounts = DB::table('operation_plan_ingredients')
            ->join('ingredients', 'ingredients.id', '=', 'operation_plan_ingredients.ingredient_id')
            ->whereNull('ingredients.archived_at')
            ->whereIn('operation_plan_ingredients.operation_plan_id', $plans->modelKeys())
            ->groupBy('operation_plan_ingredients.operation_plan_id')
            ->selectRaw('operation_plan_ingredients.operation_plan_id AS plan_id, COUNT(*) AS total')
            ->pluck('total', 'plan_id');

        return [
            'page' => $page,
            'branch' => $branch?->only(['id', 'name', 'code']),
            'plans' => $plans->map(fn (OperationPlan $plan): array => [
                'id' => $plan->id,
                'name' => $plan->name,
                'icon' => $plan->icon,
                'description' => $plan->description,
                'product_count' => (int) ($productCounts[$plan->id] ?? 0),
                'ingredient_count' => (int) ($ingredientCounts[$plan->id] ?? 0),
            ])->values()->all(),
            'active_plan_id' => $active?->id,
            'has_open_store_session' => $branch === null ? null
                : StoreSession::query()->where('branch_id', $branch->id)->where('status', StoreSessionStatus::Open)->exists(),
        ];
    }

    /**
     * @param  EloquentCollection<int, OperationPlan>  $plans
     * @return array<string, mixed>
     */
    public function plansPage(?Branch $branch, EloquentCollection $plans): array
    {
        $rows = $this->stock->rows($branch);
        $summary = $this->summary->today($branch);
        $memberships = OperationPlanProduct::query()->get(['operation_plan_id', 'product_id']);
        $recipeProducts = Recipe::query()->distinct()->pluck('product_id')->flip();
        $direct = $this->directResaleProducts($memberships->map(fn (OperationPlanProduct $membership): string => $membership->product_id)->values()->all());

        $cards = $plans->map(function (OperationPlan $plan) use ($rows, $memberships, $recipeProducts, $direct, $summary): array {
            $productIds = $memberships->where('operation_plan_id', $plan->id)->pluck('product_id')->all();
            $need = array_values(array_filter($productIds, fn (string $id): bool => ! $direct->has($id)));
            $withRecipe = array_values(array_filter($need, fn (string $id): bool => $recipeProducts->has($id)));
            $planRows = array_values(array_filter($rows, fn (array $row): bool => in_array($plan->id, $row['plan_ids'], true)));
            $market = $this->market($planRows);

            return [
                'id' => $plan->id,
                'products' => count($productIds),
                'products_needing_recipe' => count($need),
                'products_with_recipe' => count($withRecipe),
                'ingredients' => count($planRows),
                'shared_ingredients' => count(array_filter($planRows, fn (array $row): bool => count($row['plan_ids']) > 1)),
                'below_target' => $planRows !== [] && $planRows[0]['stock'] === null ? null
                    : count(array_filter($planRows, fn (array $row): bool => $row['stock'] !== null && $row['stock']['current'] < ExactQuantity::parse($row['ingredient']->target_quantity))),
                'to_buy' => $market['available'] ? count($market['auto']) : null,
                'suggested_cents' => $market['available'] ? $market['estimate_cents'] : null,
                'suggested_unknown' => $market['unknown'],
                'figures' => $summary['plans'][$plan->id] ?? null,
            ];
        })->values()->all();

        $outside = Product::query()->where('is_active', true)
            ->whereNotIn('id', OperationPlanProduct::query()->select('product_id'))
            ->orderBy('name')->limit(3)->pluck('name');
        $shared = null;
        foreach ($rows as $row) {
            if (count($row['plan_ids']) > 1) {
                $shared = $row;
                break;
            }
        }

        return [
            'cards' => $cards,
            'summary' => $this->presentSummary($summary),
            'outside' => [
                'count' => Product::query()->where('is_active', true)->whereNotIn('id', OperationPlanProduct::query()->select('product_id'))->count(),
                'examples' => $outside->all(),
            ],
            'shared' => $shared === null ? null : [
                ...$this->presentIngredient($shared),
                'plan_ids' => $shared['plan_ids'],
            ],
            'products' => $this->productPicker(),
        ];
    }

    /** @return array<string, mixed> */
    public function overviewPage(?Branch $branch, OperationPlan $plan): array
    {
        $rows = $this->planRows($this->stock->rows($branch), $plan);
        $summary = $this->summary->today($branch);
        $figures = $summary['plans'][$plan->id] ?? null;
        $market = $this->market($rows, $this->skips($branch, $plan));
        $manual = $this->manualEntries($branch, $plan);
        $recipes = $this->recipeStates($plan);
        $used = [];
        foreach ($rows as $row) {
            $quantity = $row['stock']['consumed_by_plan'][$plan->id] ?? 0;
            if ($quantity > 0) {
                $used[] = ['quantity' => $quantity, 'row' => [...$this->presentIngredient($row), 'used' => ExactQuantity::display($quantity)]];
            }
        }
        usort($used, fn (array $left, array $right): int => $right['quantity'] <=> $left['quantity']);
        $consumption = array_column(array_slice($used, 0, 7), 'row');

        return [
            'figures' => $figures,
            'business_date' => $summary['business_date'],
            'ingredients' => array_map(fn (array $row): array => $this->presentIngredient($row), $rows),
            'market' => [...$market, 'manual' => $manual, 'estimate_cents' => $market['estimate_cents'] + array_sum(array_map(fn (array $entry): int => $entry['estimate_cents'] ?? 0, $manual)), 'unknown' => $market['unknown'] + count(array_filter($manual, fn (array $entry): bool => $entry['estimate_cents'] === null))],
            'recipes' => $recipes,
            'consumption' => $consumption,
            'movements' => $branch === null ? [] : $this->movements($branch, array_map(fn (array $row): string => $row['ingredient']->id, $rows), 5),
            'summary' => $this->presentSummary($summary, $plan),
            'earlier' => $this->purchasesToday($branch, $plan),
        ];
    }

    /** @return array<string, mixed> */
    public function ingredientsPage(?Branch $branch): array
    {
        return ['ingredients' => array_map(fn (array $row): array => $this->presentIngredient($row), $this->stock->rows($branch))];
    }

    /**
     * Base recipes per Size (the one Size group only), Product-specific Add-on / Modifier Ingredient effects, the
     * Product's inventory mode (Product stock, No recipe needed or Ingredient recipe) and, for a concrete Branch, the
     * servings each Size can make now (RecipeCapacity). Instructions are listed only to explain that they never use
     * ingredients.
     *
     * @return array<string, mixed>
     */
    public function recipesPage(?Branch $branch, OperationPlan $plan): array
    {
        $products = Product::query()->whereIn('id', OperationPlanProduct::query()->where('operation_plan_id', $plan->id)->select('product_id'))
            ->with(['category:id,name', 'modifierGroups' => fn ($query) => $query->where('is_active', true)->orderBy('name')
                ->with(['options' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order')->orderBy('name')])])
            ->orderBy('name')->orderBy('id')->get();
        $resolved = $this->sizes->resolve($products->modelKeys());
        $recipes = Recipe::query()->whereIn('product_id', $products->modelKeys())->with('lines')->get()
            ->keyBy(fn (Recipe $recipe): string => $recipe->product_id.'|'.$recipe->size_key);
        $effects = ProductModifierEffect::query()->whereIn('product_id', $products->modelKeys())->with('lines')->get()
            ->keyBy(fn (ProductModifierEffect $effect): string => $effect->product_id.'|'.$effect->modifier_option_id);
        $tracked = BranchProduct::query()->whereIn('product_id', $products->modelKeys())->where('tracks_inventory', true)
            ->join('branches', 'branches.id', '=', 'branch_products.branch_id')
            ->orderBy('branches.code')
            ->get(['branch_products.product_id', 'branches.id AS branch_id', 'branches.code', 'branches.name'])->groupBy('product_id');
        $prices = $branch === null ? collect() : BranchProduct::query()->where('branch_id', $branch->id)
            ->whereIn('product_id', $products->modelKeys())->whereNotNull('price_override')->pluck('price_override', 'product_id');
        $availability = $branch === null ? [] : $this->capacity->catalog($branch, $products->map(fn (Product $product): string => $product->id)->values()->all());
        $present = fn (iterable $lines): array => collect($lines)->sortBy('ingredient_id')->map(fn (RecipeLine|ProductModifierEffectLine $line): array => [
            'ingredient_id' => $line->ingredient_id,
            'quantity' => ExactQuantity::display(ExactQuantity::parse($line->quantity)),
        ])->values()->all();

        return [
            'products' => $products->map(function (Product $product) use ($resolved, $recipes, $effects, $tracked, $prices, $availability, $present): array {
                $base = ExactMoney::cents((string) ($prices[$product->id] ?? $product->default_price));
                $servings = collect($availability[$product->id]['sizes'] ?? [])->pluck('capacity', 'key');
                $productSizes = array_map(function (array $size) use ($product, $recipes, $base, $servings, $present): array {
                    $recipe = $recipes->get($product->id.'|'.$size['key']);

                    return [
                        'key' => $size['key'],
                        'option_id' => $size['option_id'],
                        'name' => $size['name'],
                        'price_cents' => $base + $size['price_delta_cents'],
                        'lines' => $recipe === null ? null : $present($recipe->lines),
                        'servings' => $servings->get($size['key']),
                    ];
                }, $resolved['sizes'][$product->id]);
                $trackedAt = $tracked->get($product->id)?->pluck('code')->all() ?? [];
                $conflict = $resolved['conflicts'][$product->id] ?? null;
                $addOns = [];
                foreach ($product->modifierGroups->whereNull('semantic_role') as $group) {
                    foreach ($group->options as $option) {
                        $effect = $effects->get($product->id.'|'.$option->id);
                        $addOns[] = [
                            'option_id' => $option->id,
                            'name' => $option->name,
                            'group_name' => $group->name,
                            'price_delta_cents' => ExactMoney::signedCents((string) $option->price_delta),
                            'lines' => $effect === null || $effect->lines->isEmpty() ? null : $present($effect->lines),
                        ];
                    }
                }

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'category' => $product->category?->name,
                    'is_active' => $product->is_active,
                    'image_url' => $this->images->safeCardUrl($product),
                    'no_recipe_needed' => $product->no_recipe_needed,
                    'tracked_at' => $trackedAt,
                    /** Every Branch whose direct Product stock blocks Ingredient recipe mode, to open its own settings. */
                    'tracked_branches' => $tracked->get($product->id)?->map(fn (BranchProduct $row): array => [
                        'id' => (string) $row->getAttribute('branch_id'),
                        'code' => (string) $row->getAttribute('code'),
                        'name' => (string) $row->getAttribute('name'),
                    ])->values()->all() ?? [],
                    'inventory_mode' => match (true) {
                        $trackedAt !== [] => 'product_stock',
                        $product->no_recipe_needed => 'no_recipe_needed',
                        default => 'recipe',
                    },
                    'size_conflict' => $conflict,
                    'state' => $conflict !== null && $trackedAt === [] && ! $product->no_recipe_needed
                        ? 'configuration_error'
                        : $this->recipeState($trackedAt !== [], $product->no_recipe_needed, $productSizes),
                    'sizes' => $productSizes,
                    'add_ons' => $addOns,
                    'instruction_groups' => $product->modifierGroups->where('semantic_role', ModifierSemanticRole::Instruction)->pluck('name')->values()->all(),
                    'settings_url' => route('products.index', ['search' => $product->name, 'edit' => $product->id], false),
                ];
            })->values()->all(),
            'ingredients' => array_map(fn (array $row): array => $this->presentIngredient($row), $this->stock->rows($branch)),
        ];
    }

    /** @return array<string, mixed> */
    public function stockPage(Branch $branch, OperationPlan $plan): array
    {
        $rows = $this->planRows($this->stock->rows($branch), $plan);

        return [
            'ingredients' => array_map(fn (array $row): array => $this->presentIngredient($row), $rows),
            'movements' => $this->movements($branch, array_map(fn (array $row): string => $row['ingredient']->id, $rows), 60),
        ];
    }

    /** @return array<string, mixed> */
    public function pamamalengkePage(?Branch $branch, OperationPlan $plan): array
    {
        $rows = $this->planRows($this->stock->rows($branch), $plan);
        $summary = $this->summary->today($branch);

        return [
            'ingredients' => array_map(fn (array $row): array => $this->presentIngredient($row), $rows),
            'market' => $this->market($rows, $this->skips($branch, $plan)),
            'manual' => $this->manualEntries($branch, $plan),
            'summary' => $this->presentSummary($summary, $plan),
            'earlier' => $this->purchasesToday($branch, $plan),
        ];
    }

    /** @return array<string, mixed> */
    public function purchasesPage(?Branch $branch, ?OperationPlan $plan, int $page): array
    {
        $query = PamamalengkePurchase::query()
            ->when($branch !== null, fn ($query) => $query->where('pamamalengke_purchases.branch_id', $branch?->id))
            ->when($plan !== null, fn ($query) => $query->where('pamamalengke_purchases.operation_plan_id', $plan?->id));
        $since = IngredientStockReport::startOfToday();
        $totals = (clone $query)
            ->where('pamamalengke_purchases.created_at', '>=', $since->subDays(6))
            ->join('store_session_expenses', 'store_session_expenses.id', '=', 'pamamalengke_purchases.store_session_expense_id')
            ->get([
                'pamamalengke_purchases.created_at',
                'pamamalengke_purchases.estimated_total',
                'pamamalengke_purchases.estimate_complete',
                DB::raw('CAST(ROUND(store_session_expenses.amount * 100) AS BIGINT) AS actual_cents'),
            ]);
        $today = $totals->filter(fn ($row): bool => $row->created_at >= $since);
        $week = $totals->filter(fn ($row): bool => $row->created_at >= $since->subDays(6));
        $paginator = $query->select('pamamalengke_purchases.*')->with(['items.ingredient:id,base_unit', 'expense:id,amount,payment_source,created_at', 'plan:id,name', 'createdBy:id,name', 'branch:id,code,name'])
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(15, ['*'], 'page', $page)->withQueryString();

        return [
            'stats' => [
                'today_cents' => (int) $today->sum('actual_cents'),
                'today_runs' => $today->count(),
                'week_cents' => (int) $week->sum('actual_cents'),
                'week_runs' => $week->count(),
                'week_estimate_cents' => (int) $week->sum(fn ($row): int => ExactMoney::cents((string) ($row->estimated_total ?? '0'))),
                'week_estimate_complete' => $week->every(fn ($row): bool => (bool) $row->estimate_complete),
            ],
            'purchases' => $paginator->through(fn (PamamalengkePurchase $purchase): array => [
                'id' => $purchase->id,
                'created_at' => $purchase->created_at?->toIso8601String(),
                'branch' => $purchase->branch?->only(['id', 'code', 'name']),
                'plan' => $purchase->plan?->only(['id', 'name']),
                'bought_by' => $purchase->createdBy?->name,
                'payment_source' => $purchase->expense?->payment_source,
                'expense_id' => $purchase->store_session_expense_id,
                'expense_reference' => 'EXP-'.strtoupper(substr($purchase->store_session_expense_id, 0, 8)),
                'actual_cents' => ExactMoney::cents((string) $purchase->expense?->amount),
                'estimate_cents' => $purchase->estimated_total === null ? null : ExactMoney::cents((string) $purchase->estimated_total),
                'estimate_complete' => $purchase->estimate_complete,
                'note' => $purchase->note,
                'items' => $purchase->items->map(fn (PamamalengkePurchaseItem $item): array => [
                    'name' => $item->name_snapshot,
                    'type' => $item->line_type,
                    'unit' => $item->unit_label,
                    'recommended' => $item->recommended_quantity === null ? null : ExactQuantity::display(ExactQuantity::parse($item->recommended_quantity)),
                    'quantity' => ExactQuantity::display(ExactQuantity::parse($item->actual_quantity)),
                    'unit_cost_cents' => ExactMoney::cents((string) $item->actual_unit_cost),
                    'total_cents' => ExactMoney::cents((string) $item->line_total),
                    'base_quantity' => $item->base_quantity === null ? null : ExactQuantity::display(ExactQuantity::parse($item->base_quantity)),
                    'base_unit' => $item->ingredient?->base_unit,
                    'note' => $item->note,
                ])->values()->all(),
            ]),
        ];
    }

    /**
     * Server-side Pamamalengke plan for a Plan's Ingredients: automatic suggestions (minus "skip this run"), setup
     * gaps and everything that needs no purchase. Unavailable for All Branches, whose stock is not actionable.
     *
     * @param  array<int, IngredientRow>  $rows
     * @param  array<string, bool>  $skips
     * @return array{available: bool, auto: list<array<string, mixed>>, setup: list<string>, ok: list<string>, hold: int, estimate_cents: int, unknown: int}
     */
    private function market(array $rows, array $skips = []): array
    {
        if ($rows === [] || $rows[0]['stock'] === null) {
            return ['available' => $rows === [], 'auto' => [], 'setup' => [], 'ok' => [], 'hold' => 0, 'estimate_cents' => 0, 'unknown' => 0];
        }
        $auto = [];
        $setup = [];
        $ok = [];
        $hold = 0;
        $estimate = 0;
        $unknown = 0;
        foreach ($rows as $row) {
            $recommendation = $row['recommendation'];
            $id = $row['ingredient']->id;
            if ($recommendation === null) {
                continue;
            }
            match ($recommendation['kind']) {
                'buy' => $auto[] = ['ingredient_id' => $id, 'skipped' => isset($skips[$id])],
                'setup' => $setup[] = $id,
                default => $ok[] = $id,
            };
            $hold += $recommendation['kind'] === 'hold' ? 1 : 0;
            if ($recommendation['kind'] === 'buy' && ! isset($skips[$id])) {
                $recommendation['estimate_cents'] === null ? $unknown++ : $estimate += $recommendation['estimate_cents'];
            }
        }

        return ['available' => true, 'auto' => $auto, 'setup' => $setup, 'ok' => $ok, 'hold' => $hold, 'estimate_cents' => $estimate, 'unknown' => $unknown];
    }

    /**
     * @param  IngredientRow  $row
     * @return array<string, mixed>
     */
    public function presentIngredient(array $row): array
    {
        $ingredient = $row['ingredient'];
        $stock = $row['stock'];
        $recommendation = $row['recommendation'];
        $size = ExactQuantity::parseNullable($ingredient->purchase_unit_size);

        return [
            'id' => $ingredient->id,
            'name' => $ingredient->name,
            'icon' => $ingredient->icon,
            'base_unit' => $ingredient->base_unit,
            'target' => ExactQuantity::display(ExactQuantity::parse($ingredient->target_quantity)),
            'purchase_unit' => $size === null ? null : [
                'name' => (string) $ingredient->purchase_unit_name,
                'size' => ExactQuantity::display($size),
                'cost_cents' => $ingredient->purchase_unit_cost === null ? null : ExactMoney::cents((string) $ingredient->purchase_unit_cost),
            ],
            'rule' => $ingredient->replenishment_rule->value,
            'rule_label' => IngredientStockReport::ruleLabel($ingredient),
            'reorder_point' => $ingredient->reorder_point === null ? null : ExactQuantity::display(ExactQuantity::parse($ingredient->reorder_point)),
            'plan_ids' => $row['plan_ids'],
            'locked_unit' => $row['locked_unit'],
            'archived' => $ingredient->archived_at !== null,
            'updated_at' => $ingredient->updated_at?->toIso8601String(),
            'stock' => $stock === null ? null : [
                'current' => ExactQuantity::display($stock['current']),
                'start' => ExactQuantity::display($stock['start']),
                'consumed' => ExactQuantity::display($stock['consumed']),
                'purchased' => ExactQuantity::display($stock['purchased']),
                'wastage' => ExactQuantity::display($stock['wastage']),
                'giveaway' => ExactQuantity::display($stock['giveaway']),
                'correction' => ExactQuantity::display($stock['correction'] + $stock['opening']),
                'updated_at' => $stock['updated_at'],
            ],
            'status' => $row['status'] === null ? null : ['key' => $row['status'], ...IngredientStockReport::statusLabel($row['status'])],
            'recommendation' => $recommendation === null ? null : [
                'kind' => $recommendation['kind'],
                'units' => $recommendation['units'],
                'base_quantity' => ExactQuantity::display($recommendation['base_quantity']),
                'after' => $stock === null ? null : ExactQuantity::display($stock['current'] + $recommendation['base_quantity']),
                'estimate_cents' => $recommendation['estimate_cents'],
                'reason' => $recommendation['reason'],
            ],
        ];
    }

    /**
     * Newest movements of these Ingredients at the Branch today, grouped into one entry per sale, edit, void, purchase
     * or adjustment, with Plan snapshot, reference and actor.
     *
     * @param  array<int, string>  $ingredientIds
     * @return array<int, array<string, mixed>>
     */
    public function movements(Branch $branch, array $ingredientIds, int $limit = 40): array
    {
        if ($ingredientIds === []) {
            return [];
        }
        $movements = IngredientMovement::query()
            ->where('branch_id', $branch->id)
            ->where('created_at', '>=', IngredientStockReport::startOfToday())
            ->where(fn ($query) => $query->whereIn('ingredient_id', $ingredientIds)
                ->orWhereIn('order_id', IngredientMovement::query()->select('order_id')->where('branch_id', $branch->id)
                    ->where('created_at', '>=', IngredientStockReport::startOfToday())->whereIn('ingredient_id', $ingredientIds)->whereNotNull('order_id')))
            ->with(['ingredient:id,name,base_unit', 'createdBy:id,name', 'plan:id,name', 'order:id,order_number,reference_number', 'snapshot:id,product_name_snapshot,size_name_snapshot'])
            ->orderByDesc('created_at')->orderByDesc('id')
            /** Enough rows for $limit grouped entries (one row per Ingredient), never an unbounded history. */
            ->limit(min(400, $limit * 20))
            ->get();

        $groups = [];
        foreach ($movements as $movement) {
            $key = $movement->movement_type->value.'|'.($movement->order_id ?? $movement->pamamalengke_purchase_id ?? $movement->store_session_giveaway_id ?? $movement->id).'|'.$movement->created_at->format('Y-m-d H:i:s');
            if (! isset($groups[$key])) {
                if (count($groups) >= $limit) {
                    continue;
                }
                $groups[$key] = [
                    'id' => $movement->id,
                    'type' => $movement->movement_type->value,
                    'label' => $movement->movement_type->label(),
                    'created_at' => $movement->created_at->toIso8601String(),
                    'plan' => $movement->plan?->only(['id', 'name']),
                    'order' => $movement->order === null ? null : ['id' => $movement->order->id, 'number' => $movement->order->order_number],
                    'reason' => $movement->reason,
                    'by' => $movement->createdBy?->name,
                    'products' => [],
                    'lines' => [],
                ];
            }
            if ($movement->snapshot !== null) {
                $name = trim(($movement->snapshot->size_name_snapshot ? $movement->snapshot->size_name_snapshot.' ' : '').$movement->snapshot->product_name_snapshot);
                $groups[$key]['products'][$name] = true;
            }
            $groups[$key]['lines'][] = [
                'ingredient_id' => $movement->ingredient_id,
                'name' => $movement->ingredient?->name,
                'unit' => $movement->ingredient?->base_unit,
                'delta' => ExactQuantity::display(ExactQuantity::parse($movement->quantity_delta)),
                'mine' => in_array($movement->ingredient_id, $ingredientIds, true),
            ];
        }

        return array_values(array_map(function (array $group): array {
            $group['products'] = array_keys($group['products']);

            return $group;
        }, $groups));
    }

    /**
     * @param  array{plans: array<string, array<string, mixed>>, business: array<string, mixed>, outside_plan_sales_cents: int, giveaways: array{count: int, items: int, cost_cents: int, uncosted: int}, business_date: string}  $summary
     * @return array<string, mixed>
     */
    private function presentSummary(array $summary, ?OperationPlan $plan = null): array
    {
        return [
            'business_date' => $summary['business_date'],
            'plan' => $plan === null ? null : ($summary['plans'][$plan->id] ?? null),
            'plans' => $summary['plans'],
            'business' => $summary['business'],
            'outside_plan_sales_cents' => $summary['outside_plan_sales_cents'],
            'giveaways' => $summary['giveaways'],
        ];
    }

    /**
     * Today's confirmed runs of this Plan (for "Already bought today" in View summary).
     *
     * @return array<int, array<string, mixed>>
     */
    private function purchasesToday(?Branch $branch, OperationPlan $plan): array
    {
        return PamamalengkePurchase::query()
            ->when($branch !== null, fn ($query) => $query->where('branch_id', $branch?->id))
            ->where('operation_plan_id', $plan->id)
            ->where('created_at', '>=', IngredientStockReport::startOfToday())
            ->with(['expense:id,amount', 'createdBy:id,name'])
            ->withCount('items')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (PamamalengkePurchase $purchase): array => [
                'id' => $purchase->id,
                'created_at' => $purchase->created_at?->toIso8601String(),
                'items' => (int) $purchase->getAttribute('items_count'),
                'bought_by' => $purchase->createdBy?->name,
                'expense_reference' => 'EXP-'.strtoupper(substr($purchase->store_session_expense_id, 0, 8)),
                'estimate_cents' => $purchase->estimate_complete && $purchase->estimated_total !== null ? ExactMoney::cents((string) $purchase->estimated_total) : null,
                'actual_cents' => ExactMoney::cents((string) $purchase->expense?->amount),
            ])->all();
    }

    /** @return array<string, bool> */
    private function skips(?Branch $branch, OperationPlan $plan): array
    {
        if ($branch === null) {
            return [];
        }

        return PamamalengkeListEntry::query()->where('branch_id', $branch->id)->where('operation_plan_id', $plan->id)
            ->where('entry_type', 'skip')->pluck('ingredient_id')->mapWithKeys(fn ($id): array => [(string) $id => true])->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function manualEntries(?Branch $branch, OperationPlan $plan): array
    {
        if ($branch === null) {
            return [];
        }

        return PamamalengkeListEntry::query()->where('branch_id', $branch->id)->where('operation_plan_id', $plan->id)
            ->where('entry_type', 'manual')->orderBy('created_at')->orderBy('id')->get()
            ->map(function (PamamalengkeListEntry $entry): array {
                $quantity = ExactQuantity::parse($entry->quantity);
                $cost = $entry->estimated_unit_cost === null ? null : ExactMoney::cents((string) $entry->estimated_unit_cost);

                return [
                    'id' => $entry->id,
                    'name' => (string) $entry->name,
                    'quantity' => ExactQuantity::display($quantity),
                    'unit' => (string) $entry->unit,
                    'estimated_unit_cost_cents' => $cost,
                    'estimate_cents' => $cost === null ? null : ExactQuantity::lineCents($quantity, $cost),
                    'note' => $entry->note,
                ];
            })->all();
    }

    /**
     * Recipe coverage of a Plan's Products: set, some sizes missing, missing, No recipe needed, uses Product stock, or a
     * Size group configuration error.
     *
     * @return array<int, array{id: string, name: string, state: string}>
     */
    private function recipeStates(OperationPlan $plan): array
    {
        $products = Product::query()->whereIn('id', OperationPlanProduct::query()->where('operation_plan_id', $plan->id)->select('product_id'))
            ->orderBy('name')->get(['id', 'name', 'no_recipe_needed']);
        $resolved = $this->sizes->resolve($products->modelKeys());
        $recipes = Recipe::query()->whereIn('product_id', $products->modelKeys())->whereHas('lines')->get(['product_id', 'size_key'])
            ->map(fn (Recipe $recipe): string => $recipe->product_id.'|'.$recipe->size_key)->flip();
        $tracked = BranchProduct::query()->whereIn('product_id', $products->modelKeys())->where('tracks_inventory', true)->pluck('product_id')->flip();

        return $products->map(fn (Product $product): array => [
            'id' => $product->id,
            'name' => $product->name,
            'state' => isset($resolved['conflicts'][$product->id]) && ! $tracked->has($product->id) && ! $product->no_recipe_needed
                ? 'configuration_error'
                : $this->recipeState($tracked->has($product->id), $product->no_recipe_needed, array_map(fn (array $size): array => [
                    'lines' => $recipes->has($product->id.'|'.$size['key']) ? [true] : null,
                ], $resolved['sizes'][$product->id])),
        ])->values()->all();
    }

    /** @param array<int, array{lines: array<mixed>|null}> $sizes */
    private function recipeState(bool $productStock, bool $noRecipeNeeded, array $sizes): string
    {
        if ($productStock) {
            return 'product_stock';
        }
        if ($noRecipeNeeded) {
            return 'not_needed';
        }
        $set = count(array_filter($sizes, fn (array $size): bool => ! empty($size['lines'])));

        return match (true) {
            $set === 0 => 'missing',
            $set < count($sizes) => 'partial',
            default => 'set',
        };
    }

    /**
     * Products whose sales use Product stock or are marked No recipe needed (direct resale, no Ingredient recipe).
     *
     * @param  array<int, string>  $productIds
     * @return Collection<string, int>
     */
    private function directResaleProducts(array $productIds): Collection
    {
        return Product::query()->whereKey($productIds)
            ->where(fn ($query) => $query->where('no_recipe_needed', true)
                ->orWhereIn('id', BranchProduct::query()->where('tracks_inventory', true)->select('product_id')))
            ->pluck('id')->flip();
    }

    /**
     * @param  array<int, IngredientRow>  $rows
     * @return array<int, IngredientRow>
     */
    private function planRows(array $rows, OperationPlan $plan): array
    {
        return array_values(array_filter($rows, fn (array $row): bool => in_array($plan->id, $row['plan_ids'], true)));
    }

    /** @return array<int, array{id: string, name: string, category: string|null, plan_id: string|null}> */
    private function productPicker(): array
    {
        $plans = OperationPlanProduct::query()->pluck('operation_plan_id', 'product_id');

        return Product::query()->where('is_active', true)->with('category:id,name')->orderBy('name')->orderBy('id')->get(['id', 'name', 'category_id'])
            ->map(fn (Product $product): array => [
                'id' => $product->id,
                'name' => $product->name,
                'category' => $product->category?->name,
                'plan_id' => isset($plans[$product->id]) ? (string) $plans[$product->id] : null,
            ])->values()->all();
    }
}
