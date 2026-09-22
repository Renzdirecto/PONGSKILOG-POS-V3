<?php

namespace App\Http\Controllers;

use App\Actions\Catalog\CreateModifierGroup;
use App\Actions\Catalog\SyncModifierGroupOptions;
use App\Actions\Catalog\SyncProductModifierGroups;
use App\Actions\Catalog\UpdateModifierGroup;
use App\Http\Requests\SaveModifierGroupRequest;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Support\CatalogRealtime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ModifierGroupController extends Controller
{
    public function index(): Response
    {
        $groups = ModifierGroup::query()->select(['id', 'name', 'semantic_role', 'selection_type', 'min_select', 'max_select', 'is_active'])
            ->with([
                'options' => fn ($query) => $query->select(['id', 'modifier_group_id', 'name', 'price_delta', 'sort_order', 'is_active'])->orderBy('sort_order')->orderBy('name'),
                'products' => fn ($query) => $query->select(['products.id']),
            ])
            ->orderBy('name')->get()
            ->map(function (ModifierGroup $group): array {
                return [
                    ...$group->only(['id', 'name', 'semantic_role', 'selection_type', 'min_select', 'max_select', 'is_active']),
                    'options' => $group->options,
                    'product_ids' => $group->products->modelKeys(),
                ];
            });

        return Inertia::render('catalog/modifiers', [
            'groups' => $groups,
            'products' => Product::query()->orderBy('name')->get(['id', 'name', 'is_active']),
        ]);
    }

    public function store(SaveModifierGroupRequest $request, CreateModifierGroup $create, SyncModifierGroupOptions $syncOptions): RedirectResponse
    {
        DB::transaction(function () use ($request, $create, $syncOptions): void {
            $group = $create->execute($request->user(), $request->safe()->only(['name', 'semantic_role', 'selection_type', 'min_select', 'max_select', 'is_active']));
            $options = $request->options();

            if ($options !== null) {
                $syncOptions->execute($request->user(), $group, $options);
            }
        });

        return to_route('modifier-groups.index');
    }

    public function update(SaveModifierGroupRequest $request, ModifierGroup $modifierGroup, UpdateModifierGroup $update, SyncModifierGroupOptions $syncOptions, CatalogRealtime $realtime): RedirectResponse
    {
        $wasActive = $modifierGroup->is_active;
        $modifierGroup = DB::transaction(function () use ($request, $modifierGroup, $update, $syncOptions): ModifierGroup {
            $modifierGroup = $update->execute($request->user(), $modifierGroup, $request->safe()->only(['name', 'semantic_role', 'selection_type', 'min_select', 'max_select', 'is_active']));
            $options = $request->options();

            if ($options !== null) {
                $syncOptions->execute($request->user(), $modifierGroup, $options);
            }

            return $modifierGroup;
        });
        $realtime->productsChanged($modifierGroup->products()->get(), $wasActive !== $modifierGroup->is_active);

        return to_route('modifier-groups.index');
    }

    public function updateProducts(Request $request, ModifierGroup $modifierGroup, SyncProductModifierGroups $syncGroups, CatalogRealtime $realtime): RedirectResponse
    {
        $validated = $request->validate([
            'product_ids' => ['present', 'array', 'list'],
            'product_ids.*' => ['bail', 'required', 'uuid', 'distinct', Rule::exists(Product::class, 'id')],
        ]);
        $selectedProductIds = collect(array_values(array_filter(
            $request->array('product_ids'),
            is_string(...),
        )));
        $affectedProductIds = $modifierGroup->products()->pluck('products.id')
            ->merge($selectedProductIds)
            ->unique()
            ->values();
        $products = Product::query()
            ->whereKey($affectedProductIds)
            ->with('modifierGroups:id')
            ->get();

        DB::transaction(function () use ($request, $modifierGroup, $products, $selectedProductIds, $syncGroups): void {
            foreach ($products as $product) {
                $groupIds = $product->modifierGroups->modelKeys();

                if ($selectedProductIds->contains($product->id)) {
                    $groupIds[] = $modifierGroup->id;
                } else {
                    $groupIds = array_values(array_filter(
                        $groupIds,
                        fn (string $groupId): bool => $groupId !== $modifierGroup->id,
                    ));
                }

                $syncGroups->execute($request->user(), $product, array_values(array_unique($groupIds)));
            }
        });

        $realtime->productsChanged($products);

        return to_route('modifier-groups.index');
    }
}
