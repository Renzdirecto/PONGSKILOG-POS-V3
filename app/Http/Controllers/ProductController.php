<?php

namespace App\Http\Controllers;

use App\Actions\Catalog\CreateInlineModifierGroups;
use App\Actions\Catalog\CreateProduct;
use App\Actions\Catalog\ReplaceProductImage;
use App\Actions\Catalog\SyncProductModifierGroups;
use App\Actions\Catalog\UpdateProduct;
use App\Actions\Catalog\UpsertBranchProduct;
use App\Http\Requests\SaveProductRequest;
use App\Models\Branch;
use App\Models\Category;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\User;
use App\Support\ActiveBranchContext;
use App\Support\CatalogRealtime;
use App\Support\InventoryState;
use App\Support\ProductImages;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function index(Request $request, ProductImages $images, ActiveBranchContext $activeBranchContext, InventoryState $inventoryState): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'uuid'],
            'status' => ['nullable', 'in:active,inactive,low_stock,out_of_stock'],
        ]);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $inventoryBranch = $activeBranchContext->current($user);
        $branches = ($inventoryBranch === null
            ? Branch::query()
            : Branch::query()->whereKey($inventoryBranch->id))
            ->orderBy('code')->get(['id', 'code', 'name']);
        $productsQuery = Product::query()->select('products.*')->with([
            'category',
            'modifierGroups',
            'branchProducts',
            'inventoryBalances' => fn ($query) => $query->where('branch_id', $inventoryBranch?->id)
                ->select(['id', 'product_id', 'on_hand', 'updated_at']),
        ])
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->whereLike('name', '%'.$search.'%'))
            ->when($filters['category'] ?? null, fn ($query, $category) => $query->where('category_id', $category))
            ->when(in_array($filters['status'] ?? null, ['active', 'inactive'], true), fn ($query) => $query->where('is_active', $filters['status'] === 'active'));

        if (in_array($filters['status'] ?? null, ['low_stock', 'out_of_stock'], true)) {
            if ($inventoryBranch === null) {
                $productsQuery->whereIn('products.id', []);
            } else {
                $inventoryState->filterProducts($productsQuery, $inventoryBranch, $filters['status']);
            }
        }

        $products = $productsQuery
            ->orderBy('name')->orderBy('id')->paginate(24)->withQueryString()
            ->through(function (Product $product) use ($images, $branches, $inventoryBranch, $inventoryState): array {
                $overrides = $product->branchProducts->keyBy('branch_id');
                $inventoryConfiguration = $inventoryBranch === null ? null : $overrides->get($inventoryBranch->id);
                $inventory = $inventoryBranch === null
                    ? null
                    : $inventoryState->resolve($inventoryConfiguration, $product->inventoryBalances->first());

                return [
                    ...$product->only(['id', 'name', 'description', 'category_id', 'default_price', 'is_active']),
                    'category_name' => $product->category->name,
                    'category_active' => $product->category->is_active,
                    'image_url' => $images->safeCardUrl($product),
                    'has_image' => $product->image_path !== null,
                    'modifier_group_ids' => $product->modifierGroups->modelKeys(),
                    'modifier_group_count' => $product->modifierGroups->count(),
                    'inventory' => $inventory,
                    'branch_prices' => $branches->map(function (Branch $branch) use ($product, $overrides): array {
                        $override = $overrides->get($branch->id);

                        return [
                            'branch_id' => $branch->id,
                            'code' => $branch->code,
                            'name' => $branch->name,
                            'price_override' => $override?->price_override,
                            'effective_price' => $override->price_override ?? $product->default_price,
                            'is_available' => $override->is_available ?? true,
                            'tracks_inventory' => $override->tracks_inventory ?? false,
                            'low_stock_threshold' => $override?->low_stock_threshold,
                            'effective_available' => $product->is_active && $product->category->is_active && ($override->is_available ?? true),
                        ];
                    })->all(),
                ];
            });

        return Inertia::render('catalog/products', [
            'products' => $products,
            'categories' => Category::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'icon_key', 'is_active']),
            'modifierGroups' => ModifierGroup::query()
                ->with(['options' => fn ($query) => $query->orderBy('sort_order')->orderBy('name')])
                ->orderBy('name')->get(['id', 'name', 'semantic_role', 'selection_type', 'min_select', 'max_select', 'is_active']),
            'branchConfigurations' => $branches->map(fn (Branch $branch): array => [
                'branch_id' => $branch->id,
                'code' => $branch->code,
                'name' => $branch->name,
            ]),
            'filters' => $filters,
        ]);
    }

    public function store(
        SaveProductRequest $request,
        CreateProduct $create,
        CreateInlineModifierGroups $createInlineGroups,
        SyncProductModifierGroups $sync,
        UpsertBranchProduct $upsertBranchProduct,
        ReplaceProductImage $replaceImage,
        ActiveBranchContext $activeBranchContext,
        CatalogRealtime $realtime,
    ): RedirectResponse {
        DB::transaction(function () use ($request, $create, $createInlineGroups, $sync, $upsertBranchProduct, $replaceImage, $activeBranchContext, $realtime): void {
            $user = $request->user();
            $product = $create->execute($user, $request->only(['name', 'category_id', 'description', 'default_price', 'is_active']));
            $inlineGroupIds = $createInlineGroups->execute($user, $request->validated('inline_groups', []));
            $sync->execute($user, $product, [...$request->validated('modifier_group_ids'), ...$inlineGroupIds]);

            $allowedBranches = $this->allowedConfigurationBranches($user, $activeBranchContext)->keyBy('id');
            foreach ($request->validated('branch_configs', []) as $configuration) {
                $branch = $allowedBranches->get($configuration['branch_id']);
                abort_unless($branch instanceof Branch, 403);
                $upsertBranchProduct->execute($user, $branch, $product, $configuration);
            }

            $image = $request->file('image');
            if ($image !== null) {
                try {
                    $replaceImage->execute($user, $product, $image);
                } catch (ValidationException $exception) {
                    throw $exception;
                } catch (\Throwable $exception) {
                    report($exception);
                    throw ValidationException::withMessages(['image' => 'The image could not be saved. Please try again.']);
                }
            }

            $realtime->productChanged($product, availabilityChanged: true);
        });

        return to_route('products.index');
    }

    public function update(
        SaveProductRequest $request,
        Product $product,
        UpdateProduct $update,
        CreateInlineModifierGroups $createInlineGroups,
        SyncProductModifierGroups $sync,
        UpsertBranchProduct $upsertBranchProduct,
        ReplaceProductImage $replaceImage,
        ActiveBranchContext $activeBranchContext,
        CatalogRealtime $realtime,
    ): RedirectResponse {
        DB::transaction(function () use ($request, $product, $update, $createInlineGroups, $sync, $upsertBranchProduct, $replaceImage, $activeBranchContext, $realtime): void {
            $product = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $availabilityChanged = $product->is_active !== $request->boolean('is_active')
                || $product->category_id !== $request->string('category_id')->toString();
            $user = $request->user();
            $update->execute($user, $product, $request->only(['name', 'category_id', 'description', 'default_price', 'is_active']));
            $inlineGroupIds = $createInlineGroups->execute($user, $request->validated('inline_groups', []));
            $sync->execute($user, $product, [...$request->validated('modifier_group_ids'), ...$inlineGroupIds]);

            $allowedBranches = $this->allowedConfigurationBranches($user, $activeBranchContext)->keyBy('id');
            foreach ($request->validated('branch_configs', []) as $configuration) {
                $branch = $allowedBranches->get($configuration['branch_id']);
                abort_unless($branch instanceof Branch, 403);
                $upsertBranchProduct->execute($user, $branch, $product, $configuration);
            }

            $image = $request->file('image');
            if ($image !== null) {
                try {
                    $replaceImage->execute($user, $product, $image);
                } catch (ValidationException $exception) {
                    throw $exception;
                } catch (\Throwable $exception) {
                    report($exception);
                    throw ValidationException::withMessages(['image' => 'The image could not be saved. Please try again.']);
                }
            }

            $realtime->productChanged($product, availabilityChanged: $availabilityChanged);
        });

        return back();
    }

    /** @return Collection<int, Branch> */
    private function allowedConfigurationBranches(User $user, ActiveBranchContext $activeBranchContext): Collection
    {
        $currentBranch = $activeBranchContext->current($user);

        return $currentBranch === null
            ? Branch::query()->orderBy('code')->get()
            : Branch::query()->whereKey($currentBranch->id)->get();
    }
}
