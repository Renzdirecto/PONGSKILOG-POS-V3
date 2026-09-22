<?php

namespace App\Http\Controllers;

use App\Actions\Catalog\RemoveProductImage;
use App\Actions\Catalog\ReplaceProductImage;
use App\Models\Product;
use App\Support\CatalogRealtime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProductImageController extends Controller
{
    public function store(Request $request, Product $product, ReplaceProductImage $replace, CatalogRealtime $realtime): RedirectResponse
    {
        $request->validate(['image' => ['required', 'file']]);

        try {
            $product = $replace->execute($request->user(), $product, $request->file('image'));
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['image' => 'The image could not be saved. Please try again.']);
        }

        $realtime->productChanged($product);

        return back();
    }

    public function destroy(Request $request, Product $product, RemoveProductImage $remove, CatalogRealtime $realtime): RedirectResponse
    {
        $product = $remove->execute($request->user(), $product);
        $realtime->productChanged($product);

        return back();
    }
}
