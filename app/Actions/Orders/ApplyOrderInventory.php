<?php

namespace App\Actions\Orders;

use App\Actions\Inventory\ApplyInventoryMovement;
use App\Actions\Operations\RecordOrderIngredientUsage;
use App\Enums\InventoryMovementType;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\BranchCatalog;
use Illuminate\Validation\ValidationException;

/**
 * The one committed-sale stock path shared by Pay Now and Pay Later: tracked Product stock is deducted and
 * recipe-backed Products consume Ingredient stock, once, inside the caller's commit transaction.
 */
class ApplyOrderInventory
{
    public function __construct(
        private ApplyInventoryMovement $inventory,
        private BranchCatalog $catalog,
        private RecordOrderIngredientUsage $ingredients,
    ) {}

    public function execute(Order $order, Branch $branch, User $user, InventoryMovementType $movementType, string $reason): void
    {
        $order->load('items.modifiers');
        $quantities = [];
        foreach ($order->items as $item) {
            if ($item->product_id === null) {
                throw ValidationException::withMessages(['items' => 'A product is no longer available.']);
            }
            $quantities[$item->product_id] = ($quantities[$item->product_id] ?? 0) + $item->quantity;
        }
        if ($quantities === []) {
            throw ValidationException::withMessages(['items' => 'The order must contain items.']);
        }

        ksort($quantities);
        $productIds = array_keys($quantities);
        Category::query()
            ->whereIn('id', Product::query()->whereKey($productIds)->select('category_id'))
            ->orderBy('id')
            ->sharedLock()
            ->get();
        Product::query()->whereKey($productIds)->orderBy('id')->sharedLock()->get();
        BranchProduct::query()
            ->where('branch_id', $branch->id)
            ->whereIn('product_id', $productIds)
            ->orderBy('product_id')
            ->lockForUpdate()
            ->get();

        $products = $this->catalog->productsForOrder($branch, $productIds)->keyBy('id');
        foreach ($quantities as $productId => $quantity) {
            $product = $products->get($productId);
            if ($product === null || ! $product->is_active || ! $product->category->is_active
                || $product->branchProducts->first()?->is_available === false) {
                throw ValidationException::withMessages(['items' => 'A product is no longer available. Refresh the catalog before trying again.']);
            }
            if ($this->catalog->resolveLoaded($product)['tracked']) {
                $this->inventory->execute(
                    $branch,
                    $product,
                    $movementType,
                    -$quantity,
                    $reason,
                    $user,
                    $order->id,
                );
            }
        }

        $this->ingredients->commit($order, $branch, $user, $products);
    }
}
