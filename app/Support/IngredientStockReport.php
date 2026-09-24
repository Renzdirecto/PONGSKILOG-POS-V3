<?php

namespace App\Support;

use App\Enums\IngredientMovementType;
use App\Enums\ReplenishmentRule;
use App\Models\Branch;
use App\Models\BranchIngredientStock;
use App\Models\Ingredient;
use App\Models\OperationPlanIngredient;
use App\Models\RecipeLine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Batched, read-only view of Ingredients and their canonical Branch stock: the same balance Catalog › Inventory and
 * every Operations page show. Today's figures are the Branch's movements since Asia/Manila midnight; the start of day
 * is derived from the current balance minus today's movements, so nothing is stored twice.
 *
 * @phpstan-import-type Recommendation from ReplenishmentAdvisor
 *
 * @phpstan-type StockFigures array{current: int, start: int, consumed: int, purchased: int, wastage: int, giveaway: int, correction: int, opening: int, consumed_by_plan: array<string, int>, updated_at: string|null}
 * @phpstan-type IngredientRow array{ingredient: Ingredient, plan_ids: array<int, string>, locked_unit: bool, stock: StockFigures|null, recommendation: Recommendation|null, status: string|null}
 */
class IngredientStockReport
{
    public function __construct(private ReplenishmentAdvisor $advisor) {}

    /**
     * @param  bool  $includeArchived  archived Ingredients are listed only where history needs them
     * @return array<int, IngredientRow>
     */
    public function rows(?Branch $branch, bool $includeArchived = false): array
    {
        $ingredients = Ingredient::query()
            ->when(! $includeArchived, fn ($query) => $query->whereNull('archived_at'))
            ->orderBy('name')->orderBy('id')->get();
        if ($ingredients->isEmpty()) {
            return [];
        }
        $ids = $ingredients->map(fn (Ingredient $ingredient): string => $ingredient->id)->values()->all();
        $plans = OperationPlanIngredient::query()
            ->join('operation_plans', 'operation_plans.id', '=', 'operation_plan_ingredients.operation_plan_id')
            ->whereNull('operation_plans.archived_at')
            ->whereIn('operation_plan_ingredients.ingredient_id', $ids)
            ->orderBy('operation_plans.name')
            ->get(['operation_plan_ingredients.ingredient_id', 'operation_plan_ingredients.operation_plan_id'])
            ->groupBy('ingredient_id')
            ->map(fn (Collection $rows): array => $rows->pluck('operation_plan_id')->map(fn ($id): string => (string) $id)->values()->all());
        /** Every movement bumps its balance version, so a used balance marks the base unit as locked. */
        $withMovements = BranchIngredientStock::query()->whereIn('ingredient_id', $ids)->where('version', '>', 0)->distinct()->pluck('ingredient_id')->flip();
        $inRecipes = RecipeLine::query()->whereIn('ingredient_id', $ids)->distinct()->pluck('ingredient_id')->flip();
        $figures = $branch === null ? [] : $this->figures($branch, $ids);

        return $ingredients->map(function (Ingredient $ingredient) use ($plans, $withMovements, $inRecipes, $figures, $branch): array {
            $stock = $branch === null ? null : ($figures[$ingredient->id] ?? $this->emptyFigures());
            $recommendation = $stock === null ? null : $this->advisor->recommend($ingredient, $stock['current']);

            return [
                'ingredient' => $ingredient,
                'plan_ids' => $plans->get($ingredient->id, []),
                'locked_unit' => $withMovements->has($ingredient->id) || $inRecipes->has($ingredient->id),
                'stock' => $stock,
                'recommendation' => $recommendation,
                'status' => $stock === null || $recommendation === null ? null : $this->status($ingredient, $stock['current'], $recommendation),
            ];
        })->values()->all();
    }

    /**
     * NEGATIVE (count needed), NEEDS SETUP, OUT OF STOCK, TO BUY, BELOW TARGET, ABOVE TARGET or AT TARGET.
     *
     * @param  Recommendation  $recommendation
     */
    public function status(Ingredient $ingredient, int $current, array $recommendation): string
    {
        $target = ExactQuantity::parse($ingredient->target_quantity);

        return match (true) {
            $current < 0 => 'negative',
            $recommendation['kind'] === 'setup' => 'setup',
            $current === 0 => 'out',
            $recommendation['kind'] === 'buy' => 'buy',
            $current < $target => 'below',
            $current > $target => 'above',
            default => 'at',
        };
    }

    /** @return array{label: string, tone: 'red'|'amber'|'green'|'neutral'|'outline'} */
    public static function statusLabel(string $status): array
    {
        return match ($status) {
            'negative' => ['label' => 'Negative · count needed', 'tone' => 'red'],
            'setup' => ['label' => 'Needs setup', 'tone' => 'outline'],
            'out' => ['label' => 'Out of stock', 'tone' => 'red'],
            'buy' => ['label' => 'To buy', 'tone' => 'amber'],
            'below' => ['label' => 'Below target', 'tone' => 'neutral'],
            'above' => ['label' => 'Above target', 'tone' => 'green'],
            default => ['label' => 'At target', 'tone' => 'green'],
        };
    }

    public static function ruleLabel(Ingredient $ingredient): string
    {
        if ($ingredient->purchase_unit_size === null || trim((string) $ingredient->purchase_unit_name) === '') {
            return 'Not set';
        }

        return match ($ingredient->replenishment_rule) {
            ReplenishmentRule::TopUp => 'Top up when below target',
            ReplenishmentRule::Reorder => 'Reorder at '.ReplenishmentAdvisor::quantity(ExactQuantity::parse($ingredient->reorder_point ?? '0'), $ingredient->base_unit).' or lower',
            ReplenishmentRule::None => 'No automatic suggestion',
        };
    }

    /** Start of the current Asia/Manila calendar day, in UTC, for "today" stock movement figures. */
    public static function startOfToday(): CarbonImmutable
    {
        return CarbonImmutable::now(ReportPeriod::TIMEZONE)->startOfDay()->utc();
    }

    /**
     * @param  array<int, string>  $ingredientIds
     * @return array<string, StockFigures>
     */
    private function figures(Branch $branch, array $ingredientIds): array
    {
        $figures = [];
        BranchIngredientStock::query()->where('branch_id', $branch->id)->whereIn('ingredient_id', $ingredientIds)
            ->get(['ingredient_id', 'on_hand', 'updated_at'])
            ->each(function (BranchIngredientStock $stock) use (&$figures): void {
                $figures[$stock->ingredient_id] = [
                    ...$this->emptyFigures(),
                    'current' => ExactQuantity::parse($stock->on_hand),
                    'updated_at' => $stock->updated_at?->toIso8601String(),
                ];
            });
        if ($figures === []) {
            return [];
        }

        DB::table('ingredient_movements')
            ->where('branch_id', $branch->id)
            ->whereIn('ingredient_id', array_keys($figures))
            ->where('created_at', '>=', self::startOfToday())
            ->groupBy('ingredient_id', 'movement_type', 'operation_plan_id')
            ->get([
                'ingredient_id', 'movement_type', 'operation_plan_id',
                DB::raw('ROUND(SUM(quantity_delta), 4) AS quantity'),
            ])
            ->each(function (object $row) use (&$figures): void {
                $quantity = ExactQuantity::parse($row->quantity);
                $figure = &$figures[(string) $row->ingredient_id];
                $type = IngredientMovementType::from((string) $row->movement_type);
                $figure['start'] -= $quantity;
                match ($type) {
                    IngredientMovementType::SaleConsumption, IngredientMovementType::OrderEditAdjustment, IngredientMovementType::VoidRestoration => $figure['consumed'] -= $quantity,
                    IngredientMovementType::PurchaseRestock => $figure['purchased'] += $quantity,
                    IngredientMovementType::Wastage => $figure['wastage'] += $quantity,
                    IngredientMovementType::Giveaway, IngredientMovementType::GiveawayReversal => $figure['giveaway'] += $quantity,
                    IngredientMovementType::CountCorrection => $figure['correction'] += $quantity,
                    IngredientMovementType::OpeningBalance => $figure['opening'] += $quantity,
                };
                if ($type->isOrderConsumption() && $row->operation_plan_id !== null) {
                    $plan = (string) $row->operation_plan_id;
                    $figure['consumed_by_plan'][$plan] = ($figure['consumed_by_plan'][$plan] ?? 0) - $quantity;
                }
                unset($figure);
            });

        foreach ($figures as $ingredientId => $figure) {
            $figures[$ingredientId]['start'] += $figure['current'];
        }

        return $figures;
    }

    /** @return StockFigures */
    private function emptyFigures(): array
    {
        return ['current' => 0, 'start' => 0, 'consumed' => 0, 'purchased' => 0, 'wastage' => 0, 'giveaway' => 0, 'correction' => 0, 'opening' => 0, 'consumed_by_plan' => [], 'updated_at' => null];
    }
}
