<?php

namespace App\Support;

use App\Enums\ModifierSemanticRole;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\Product;
use App\Models\Recipe;
use Illuminate\Database\Eloquent\Collection;

/**
 * The existing Catalog size concept: the active options of the one active `size` Modifier Group assigned to a Product.
 * Only that group creates base Recipe variants; Add-on / Modifier and Instruction groups never do. A Product without
 * one has a single base size, whose recipe key is Recipe::BASE_SIZE.
 *
 * A Product may have at most one active Size group (enforced when Groups are assigned or change role). Legacy data with
 * more than one is a configuration error: it is reported, never resolved by silently picking one of the groups.
 *
 * @phpstan-type Size array{key: string, option_id: string|null, name: string, price_delta_cents: int}
 */
class ProductSizes
{
    /**
     * @param  array<int, string>  $productIds
     * @return array<string, array<int, Size>> a Product with a Size group conflict has no valid sizes (empty list)
     */
    public function forProducts(array $productIds): array
    {
        return $this->resolve($productIds)['sizes'];
    }

    /**
     * Products with more than one active Size group, with the names of those groups.
     *
     * @param  array<int, string>  $productIds
     * @return array<string, list<string>>
     */
    public function conflicts(array $productIds): array
    {
        return $this->resolve($productIds)['conflicts'];
    }

    /**
     * @param  array<int, string>  $productIds
     * @return array{sizes: array<string, array<int, Size>>, conflicts: array<string, list<string>>}
     */
    public function resolve(array $productIds): array
    {
        if ($productIds === []) {
            return ['sizes' => [], 'conflicts' => []];
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

        /** @var array<string, list<ModifierGroup>> $byProduct */
        $byProduct = [];
        foreach ($groups as $group) {
            foreach ($group->products as $product) {
                $byProduct[$product->id][] = $group;
            }
        }

        $sizes = [];
        $conflicts = [];
        foreach ($byProduct as $productId => $productGroups) {
            if (count($productGroups) > 1) {
                $conflicts[$productId] = array_map(fn (ModifierGroup $group): string => $group->name, $productGroups);
                $sizes[$productId] = [];

                continue;
            }
            $group = $productGroups[0];
            if ($group->options->isNotEmpty()) {
                $sizes[$productId] = $group->options->map(fn (ModifierOption $option): array => [
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

        return ['sizes' => $sizes, 'conflicts' => $conflicts];
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

    /**
     * User-facing configuration error for a Product with more than one active Size group.
     *
     * @param  list<string>  $groupNames
     */
    public static function conflictMessage(string $productName, array $groupNames): string
    {
        return $productName.' has more than one active Size group ('.implode(', ', $groupNames).'). Keep one Size group in Catalog › Products; only one can define base recipes.';
    }
}
