<?php

namespace App\Http\Controllers;

use App\Actions\Catalog\UpsertBranchProduct;
use App\Models\Branch;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BranchProductController extends Controller
{
    public function update(Request $request, Product $product, Branch $branch, UpsertBranchProduct $upsert): RedirectResponse
    {
        DB::transaction(function () use ($request, $product, $branch, $upsert): void {
            $product = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $upsert->execute($request->user(), $branch, $product, $request->only([
                'price_override', 'is_available', 'tracks_inventory', 'low_stock_threshold',
            ]));
        });

        return back();
    }
}
