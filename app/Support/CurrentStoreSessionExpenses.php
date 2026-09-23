<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\StoreSession;
use App\Models\StoreSessionExpense;
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
