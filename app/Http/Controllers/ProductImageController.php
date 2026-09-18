<?php

namespace App\Http\Controllers;

use App\Actions\Catalog\RemoveProductImage;
use App\Actions\Catalog\ReplaceProductImage;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProductImageController extends Controller
{
    public function store(Request $request, Product $product, ReplaceProductImage $replace): RedirectResponse
    {
        $request->validate(['image' => ['required', 'file']]);

        try {
            $replace->execute($request->user(), $product, $request->file('image'));
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['image' => 'The image could not be saved. Please try again.']);
        }

        return back();
    }

    public function destroy(Request $request, Product $product, RemoveProductImage $remove): RedirectResponse
    {
        $remove->execute($request->user(), $product);

        return back();
    }
}
