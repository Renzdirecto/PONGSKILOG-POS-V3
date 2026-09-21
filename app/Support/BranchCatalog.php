<?php

namespace App\Support;

use App\Enums\CategoryIcon;
use App\Models\Branch;
use App\Models\Category;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;

class BranchCatalog
{
    public function __construct(private ProductImages $images, private InventoryState $inventoryState) {}

    /**
     * @return array{
     *     categories: list<array{id: string, name: string, icon_key: string}>,
     *     products: list<array{id: string, name: string, description: string|null, category_id: string, category_name: string, effective_price: string, is_available: bool, availability_reason: string|null, stock_status: string, tracks_inventory: bool, on_hand: int|null, image_url: string|null, has_modifiers: bool, modifier_groups?: list<array<string, mixed>>}>
     * }
     */
    public function browse(Branch $branch, bool $customization = false): array
    {
        $categories = Category::query()
            ->whereHas('products')
            ->orderBy('sort_order')->orderBy('name')->orderBy('id')
            ->with(['products' => fn ($query) => $query
                ->select(['id', 'category_id', 'name', 'description', 'default_price', 'image_path', 'is_active'])
                ->when($customization, fn ($query) => $query->with($this->modifierRelations()))
                ->orderBy('name')->orderBy('id')
                ->withExists(['modifierGroups as has_modifiers' => fn ($query) => $query->where('is_active', true)])
                ->with(['branchProducts' => fn ($query) => $query
                    ->where('branch_id', $branch->getKey())
                    ->select(['id', 'product_id', 'price_override', 'is_available', 'tracks_inventory', 'low_stock_threshold']),
                    'inventoryBalances' => fn ($query) => $query
                        ->where('branch_id', $branch->getKey())
                        ->select(['id', 'product_id', 'on_hand']),
                ]),
            ])
            ->get(['id', 'name', 'icon_key', 'is_active']);

        $products = [];

        foreach ($categories as $category) {
            foreach ($category->products as $product) {
                $product->setRelation('category', $category);
                $state = $this->resolveLoaded($product);
                $products[] = [
                    'id' => $product->id,
                    'name' => $product->name,
                    'description' => $product->description,
                    'category_id' => $category->id,
                    'category_name' => $category->name,
                    'effective_price' => $state['effective_price'],
                    'is_available' => $state['is_available'],
                    'availability_reason' => $state['availability_reason'],
                    'stock_status' => $state['stock_status'],
                    'tracks_inventory' => $state['tracked'],
                    'on_hand' => $state['tracked'] ? $state['on_hand'] : null,
                    'image_url' => $this->images->safeCardUrl($product),
                    'has_modifiers' => (bool) $product->getAttribute('has_modifiers'),
                    ...($customization ? ['modifier_groups' => $this->modifiers($product)] : []),
                ];
            }
        }

        return [
            'categories' => array_values($categories->map(function (Category $category): array {
                $icon = $category->getAttribute('icon_key');

                return [
                    'id' => $category->id,
                    'name' => $category->name,
                    'icon_key' => $icon instanceof CategoryIcon ? $icon->value : CategoryIcon::Food->value,
                ];
            })->all()),
            'products' => $products,
        ];
    }

    /** @param list<string> $ids
     * @return Collection<int, Product>
     */
    public function productsForOrder(Branch $branch, array $ids): Collection
    {
        return Product::query()->whereKey($ids)->with([
            'category',
            'branchProducts' => fn ($query) => $query->where('branch_id', $branch->id),
            'inventoryBalances' => fn ($query) => $query->where('branch_id', $branch->id),
            ...$this->modifierRelations(),
        ])->get();
    }

    /** Resolve only freshly loaded, branch-scoped relations.
     * @return array{effective_price: string, is_available: bool, availability_reason: string|null, stock_status: string, tracked: bool, on_hand: int|null}
     */
    public function resolveLoaded(Product $product): array
    {
        $override = $product->branchProducts->first();
        $stock = $this->inventoryState->resolve($override, $product->inventoryBalances->first());

        $availabilityReason = match (true) {
            ! $product->is_active => 'product_disabled',
            ! $product->category->is_active => 'category_disabled',
            $override?->is_available === false => 'branch_unavailable',
            $stock['status'] === 'out_of_stock' => 'out_of_stock',
            default => null,
        };

        return [
            'effective_price' => $override->price_override ?? $product->default_price,
            'is_available' => $availabilityReason === null,
            'availability_reason' => $availabilityReason,
            'stock_status' => $stock['status'],
            'tracked' => $stock['tracked'],
            'on_hand' => $stock['on_hand'],
        ];
    }

    public function effectivePrice(Product $product, Branch $branch): string
    {
        return $this->resolveLoaded($this->productsForOrder($branch, [$product->id])->sole())['effective_price'];
    }

    public function isAvailable(Product $product, Branch $branch): bool
    {
        return $this->resolveLoaded($this->productsForOrder($branch, [$product->id])->sole())['is_available'];
    }

    /** @return array<string, \Closure> */
    private function modifierRelations(): array
    {
        return ['modifierGroups' => fn ($query) => $query->where('is_active', true)->orderBy('name')->orderBy('id')
            ->with(['options' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order')->orderBy('id')])];
    }

    /** @return list<array<string, mixed>> */
    private function modifiers(Product $product): array
    {
        return array_values($product->modifierGroups->map(fn (ModifierGroup $group): array => [
            'id' => $group->id,
            'name' => $group->name,
            'semantic_role' => $group->semantic_role?->value,
            'selection_type' => $group->selection_type->value,
            'min_select' => $group->min_select,
            'max_select' => $group->max_select,
            'options' => $group->options->map(fn (ModifierOption $option): array => [
                'id' => $option->id,
                'name' => $option->name,
                'price_delta' => $option->price_delta,
                'sort_order' => $option->sort_order,
            ])->all(),
        ])->all());
    }
}
