<?php

namespace App\Support;

use App\Enums\GiveawayStockMode;
use App\Enums\IngredientMovementType;
use App\Enums\ModifierSemanticRole;
use App\Models\Branch;
use App\Models\IngredientMovement;
use App\Models\StoreSession;
use App\Models\StoreSessionExpense;
use App\Models\StoreSessionGiveaway;
use App\Models\StoreSessionInventoryAdjustment;
use Illuminate\Support\Facades\DB;

class CurrentStoreSessionExpenses
{
    /** @return array<string, mixed> */
    public function for(Branch $branch, StoreSession $session): array
    {
        $totals = (array) DB::table('store_session_expenses')
            ->where('branch_id', $branch->id)
            ->where('store_session_id', $session->id)
            ->selectRaw("COALESCE(SUM(CASE WHEN payment_source = 'cash' THEN amount ELSE 0 END), 0) AS cash_total")
            ->selectRaw("COALESCE(SUM(CASE WHEN payment_source = 'cashless' THEN amount ELSE 0 END), 0) AS cashless_total")
            ->selectRaw('COALESCE(SUM(amount), 0) AS total')
            ->first();

        $query = StoreSessionExpense::query()
            ->where('branch_id', $branch->id)
            ->where('store_session_id', $session->id);
        $count = (clone $query)->count();
        $expenses = $query->with(['createdBy:id,name', 'item.product:id,name', 'inventoryMovements:id,store_session_expense_id,quantity_delta'])
            ->latest('created_at')->latest('id')->limit(50)->get();

        $products = DB::table('branch_products')
            ->join('products', 'products.id', '=', 'branch_products.product_id')
            ->leftJoin('branch_inventory', function ($join): void {
                $join->on('branch_inventory.branch_id', '=', 'branch_products.branch_id')
                    ->on('branch_inventory.product_id', '=', 'branch_products.product_id');
            })
            ->where('branch_products.branch_id', $branch->id)
            ->where('branch_products.tracks_inventory', true)
            ->where('products.is_active', true)
            ->orderBy('products.name')
            ->get(['products.id', 'products.name', DB::raw('COALESCE(branch_inventory.on_hand, 0) AS on_hand')])
            ->map(function (object $product): array {
                $values = (array) $product;

                return ['id' => $values['id'], 'name' => $values['name'], 'on_hand' => (int) $values['on_hand']];
            })
            ->all();

        $adjustments = StoreSessionInventoryAdjustment::query()
            ->where('branch_id', $branch->id)
            ->where('store_session_id', $session->id);
        $adjustmentCount = (clone $adjustments)->count();
        $giveaways = StoreSessionGiveaway::query()
            ->where('branch_id', $branch->id)
            ->where('store_session_id', $session->id);
        $giveawayCount = (clone $giveaways)->count();

        return [
            'expense_totals' => [
                'cash' => $this->money($totals['cash_total'] ?? 0),
                'cashless' => $this->money($totals['cashless_total'] ?? 0),
                'total' => $this->money($totals['total'] ?? 0),
            ],
            'expenses' => $expenses->map(fn (StoreSessionExpense $expense): array => $this->expense($expense))->all(),
            'expense_count' => $count,
            'expenses_truncated' => $count > 50,
            'restock_products' => $products,
            /** Stock-only records: listed with the session history but never part of the money totals. */
            'inventory_adjustments' => $adjustments->with(['createdBy:id,name', 'product:id,name'])
                ->latest('created_at')->latest('id')->limit(50)->get()
                ->map(fn (StoreSessionInventoryAdjustment $adjustment): array => [
                    'id' => $adjustment->id,
                    'product_name' => $adjustment->product->name,
                    'quantity' => $adjustment->quantity,
                    'reason_code' => $adjustment->reason_code->value,
                    'reason_label' => $adjustment->reason_code->label(),
                    'note' => $adjustment->note,
                    'created_at' => $adjustment->created_at?->toIso8601String(),
                    'created_by' => ['name' => $adjustment->createdBy->name],
                ])->all(),
            'inventory_adjustment_count' => $adjustmentCount,
            /** Free items given away: stock-only records with ₱0 revenue, never part of the money totals. */
            'giveaways' => $giveaways->with(self::giveawayRelations())
                ->latest('created_at')->latest('id')->limit(50)->get()
                ->map(fn (StoreSessionGiveaway $giveaway): array => $this->giveaway($giveaway))->all(),
            'giveaway_count' => $giveawayCount,
        ];
    }

    /** @return array<int|string, mixed> */
    public static function giveawayRelations(): array
    {
        return [
            'createdBy:id,name',
            'reversal.createdBy:id,name',
            'ingredientMovements' => fn ($query) => $query->where('movement_type', IngredientMovementType::Giveaway->value)
                ->orderBy('ingredient_id')->with('ingredient:id,name,base_unit'),
        ];
    }

    /** @return array<string, mixed> */
    public function giveaway(StoreSessionGiveaway $giveaway): array
    {
        $names = fn (?string $role): array => array_values(array_map(
            fn (array $selection): string => $selection['option_name'],
            array_filter($giveaway->selections, fn (array $selection): bool => $selection['semantic_role'] === $role),
        ));

        return [
            'id' => $giveaway->id,
            'product_name' => $giveaway->product_name_snapshot,
            'size_name' => $giveaway->size_name_snapshot,
            'add_ons' => $names(null),
            'instructions' => $names(ModifierSemanticRole::Instruction->value),
            'quantity' => $giveaway->quantity,
            'reason_code' => $giveaway->reason_code->value,
            'reason_label' => $giveaway->reason_code->label(),
            'note' => $giveaway->note,
            'stock_mode' => $giveaway->stock_mode->value,
            /** Exactly what left the shelf: the recorded movements, never today's recipe. */
            'stock_effects' => match ($giveaway->stock_mode) {
                GiveawayStockMode::ProductStock => [['name' => $giveaway->product_name_snapshot, 'quantity' => (string) $giveaway->quantity, 'unit' => 'pc']],
                GiveawayStockMode::Recipe => $giveaway->ingredientMovements->map(fn (IngredientMovement $movement): array => [
                    'name' => (string) $movement->ingredient?->name,
                    'quantity' => ExactQuantity::display(-ExactQuantity::parse($movement->quantity_delta)),
                    'unit' => (string) $movement->ingredient?->base_unit,
                ])->values()->all(),
                GiveawayStockMode::None => [],
            },
            'created_at' => $giveaway->created_at?->toIso8601String(),
            'created_by' => ['name' => $giveaway->createdBy->name],
            'reversal' => $giveaway->reversal === null ? null : [
                'reason' => $giveaway->reversal->reason,
                'created_at' => $giveaway->reversal->created_at?->toIso8601String(),
                'created_by' => ['name' => $giveaway->reversal->createdBy->name],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function expense(StoreSessionExpense $expense): array
    {
        return [
            'id' => $expense->id,
            'description' => $expense->description,
            'amount' => $expense->amount,
            'payment_source' => $expense->payment_source,
            'note' => $expense->note,
            'created_at' => $expense->created_at->toIso8601String(),
            'created_by' => ['name' => $expense->createdBy->name],
            'item' => $expense->item === null ? null : [
                'product_id' => $expense->item->product_id,
                'product_name' => $expense->item->product->name,
                'quantity' => $expense->item->quantity,
                'movement_id' => $expense->inventoryMovements->first()?->id,
            ],
            'receipt' => $expense->receipt_image_path === null ? null : [
                'name' => $expense->receipt_original_name,
                'url' => route('store-session-expenses.receipt', $expense->id, false),
            ],
        ];
    }

    private function money(mixed $value): string
    {
        $decimal = (string) $value;
        if (! preg_match('/\A([0-9]+)(?:\.([0-9]{1,2}))?\z/', $decimal, $matches)) {
            throw new \LogicException('The expense aggregate is not an exact decimal amount.');
        }

        $whole = ltrim($matches[1], '0') ?: '0';

        return $whole.'.'.str_pad($matches[2] ?? '', 2, '0');
    }
}
