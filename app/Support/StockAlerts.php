<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\Product;
use App\Notifications\AdminAlert;

/**
 * Out-of-stock alerts for the Control Center. The canonical stock writers call these only for the movement that takes
 * a balance from above zero to zero or below while holding that balance's row lock, so exactly one alert follows each
 * real in-stock → out-of-stock transition; requests that see an already-empty balance never alert again, and a
 * restock followed by a new sell-out alerts once more.
 */
class StockAlerts
{
    public static function productOutOfStock(Branch $branch, Product $product): void
    {
        $name = $product->name;
        AdminNotifier::superAdmins(new AdminAlert(
            'stock',
            $name.' is out of stock',
            $branch->name.' ('.$branch->code.') has no '.$name.' left. It cannot be sold there until it is restocked.',
            route('inventory.index', ['search' => $name, 'stock_status' => 'out_of_stock'], false),
        ));
    }

    public static function ingredientOutOfStock(Branch $branch, string $ingredientId): void
    {
        $branchLabel = $branch->name.' ('.$branch->code.')';
        AdminNotifier::superAdmins(function () use ($branchLabel, $ingredientId): ?AdminAlert {
            $name = Ingredient::query()->whereKey($ingredientId)->value('name');

            return is_string($name) ? new AdminAlert(
                'stock',
                'Ingredient '.$name.' ran out',
                $branchLabel.' has no '.$name.' left. Recipes that use it are unavailable there until it is restocked.',
                route('operations.stock', [], false),
            ) : null;
        });
    }
}
