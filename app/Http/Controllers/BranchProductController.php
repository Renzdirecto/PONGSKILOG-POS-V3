<?php

namespace App\Http\Controllers;

use App\Actions\Catalog\UpsertBranchProduct;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Support\CatalogRealtime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BranchProductController extends Controller
{
    public function update(Request $request, Product $product, Branch $branch, UpsertBranchProduct $upsert, CatalogRealtime $realtime): RedirectResponse
    {
        DB::transaction(function () use ($request, $product, $branch, $upsert, $realtime): void {
            $product = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $configuration = BranchProduct::query()
                ->where('branch_id', $branch->id)
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->first();
            $availabilityChanged = $configuration === null
                || $configuration->is_available !== $request->boolean('is_available')
                || $configuration->tracks_inventory !== $request->boolean('tracks_inventory');
            $upsert->execute($request->user(), $branch, $product, $request->only([
                'price_override', 'is_available', 'tracks_inventory', 'low_stock_threshold',
            ]));
            $realtime->productChanged($product, $branch, $availabilityChanged);
        });

        return back();
    }
}
