<?php

namespace App\Support;

use App\Enums\ModifierSemanticRole;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\Product;
use App\Models\Recipe;
use Illuminate\Database\Eloquent\Collection;

/**
 * The existing Catalog size concept: the active options of an active `size` Modifier Group assigned to a Product.
 * A Product without one has a single base size, whose recipe key is Recipe::BASE_SIZE.
 *
 * @phpstan-type Size array{key: string, option_id: string|null, name: string, price_delta_cents: int}
 */
class ProductSizes
{
    /**
     * @param  array<int, string>  $productIds
     * @return array<string, array<int, Size>>
     */
    public function forProducts(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }
        $groups = ModifierGroup::query()
            ->where('semantic_role', ModifierSemanticRole::Size->value)
            ->where('is_active', true)
            ->whereHas('products', fn ($query) => $query->whereIn('products.id', $productIds))
            ->with([
                'products' => fn ($query) => $query->whereIn('products.id', $productIds)->select('products.id'),
                'options' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order')->orderBy('name'),
            ])
            ->orderBy('name')
            ->get();

        $sizes = [];
        foreach ($groups as $group) {
            foreach ($group->products as $product) {
                if (isset($sizes[$product->id]) || $group->options->isEmpty()) {
                    continue;
                }
                $sizes[$product->id] = $group->options->map(fn (ModifierOption $option): array => [
                    'key' => $option->id,
                    'option_id' => $option->id,
                    'name' => $option->name,
                    'price_delta_cents' => ExactMoney::signedCents((string) $option->price_delta),
                ])->values()->all();
            }
        }

        foreach ($productIds as $productId) {
            $sizes[$productId] ??= [['key' => Recipe::BASE_SIZE, 'option_id' => null, 'name' => 'Regular', 'price_delta_cents' => 0]];
        }

        return $sizes;
    }

    /** @return array<int, Size> */
    public function forProduct(Product $product): array
    {
        return $this->forProducts([$product->id])[$product->id];
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return array<string, array<int, Size>>
     */
    public function forCollection(Collection $products): array
    {
        return $this->forProducts($products->map(fn (Product $product): string => $product->id)->values()->all());
    }
}
