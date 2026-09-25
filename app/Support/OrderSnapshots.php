<?php

namespace App\Support;

use App\Enums\ModifierSelectionType;
use App\Enums\ModifierSemanticRole;
use App\Enums\OrderType;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Category;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\OrderItem;
use App\Models\OrderItemModifier;
use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderSnapshots
{
    public function __construct(private BranchCatalog $catalog, private RecipeCapacity $recipes) {}

    /** @param array{order_type: string, branch_table_id?: string|null, customer_label?: string|null, items: list<array{existing_order_item_id?: string|null, product_id: string, quantity: int, notes?: string|null, modifiers: list<array{group_id: string, option_id: string}>}>} $data
     * @param  Collection<int, OrderItem>|null  $existingItems
     * @return array{attributes: array<string, mixed>, items: list<array<string, mixed>>, modifiers: list<array<string, mixed>>}
     */
    public function prepare(Branch $branch, array $data, string $orderId, bool $lockCatalog = false, ?Collection $existingItems = null): array
    {
        if ($lockCatalog) {
            $ids = array_values(array_unique(array_column($data['items'], 'product_id')));
            Category::query()->whereIn('id', Product::query()->whereKey($ids)->select('category_id'))->orderBy('id')->sharedLock()->get();
            Product::query()->whereKey($ids)->orderBy('id')->sharedLock()->get();
            BranchProduct::query()->where('branch_id', $branch->id)->whereIn('product_id', $ids)->orderBy('product_id')->lockForUpdate()->get();
            ModifierGroup::query()->whereHas('products', fn ($query) => $query->whereIn('products.id', $ids))->orderBy('id')->sharedLock()->get();
            ModifierOption::query()->whereHas('modifierGroup.products', fn ($query) => $query->whereIn('products.id', $ids))->orderBy('id')->sharedLock()->get();
        }
        $type = OrderType::from($data['order_type']);
        $tableId = ($data['branch_table_id'] ?? null) ?: null;
        if ($tableId !== null && ! $branch->tables()->whereKey($tableId)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['branch_table_id' => 'Choose an active table in this branch.']);
        }
        if ($lockCatalog && $tableId !== null) {
            $branch->tables()->whereKey($tableId)->where('is_active', true)->sharedLock()->firstOrFail();
        }
        $label = trim($data['customer_label'] ?? '');

        $products = $this->catalog->productsForOrder($branch, array_values(array_unique(array_column($data['items'], 'product_id'))))->keyBy('id');
        $requested = [];
        foreach ($data['items'] as $line) {
            $requested[$line['product_id']] = ($requested[$line['product_id']] ?? 0) + $line['quantity'];
        }
        $items = [];
        $modifiers = [];
        $subtotal = 0;
        foreach ($data['items'] as $index => $line) {
            $product = $products->get($line['product_id']);
            if ($product === null) {
                throw ValidationException::withMessages(["items.$index.product_id" => 'This product is no longer available. Remove it or refresh the catalog.']);
            }
            $state = $this->catalog->resolveLoaded($product);
            $existing = $existingItems?->firstWhere('id', $line['existing_order_item_id'] ?? null);
            $existingOptionIds = $existing?->modifiers->pluck('modifier_option_id')->sort()->values()->all();
            $requestedOptionIds = collect($line['modifiers'])->pluck('option_id')->sort()->values()->all();
            $preserveSnapshot = $existing !== null && $existing->product_id === $product->id && $existingOptionIds === $requestedOptionIds;
            if ($existingItems === null && ! $preserveSnapshot && $state['tracked'] && $requested[$product->id] > $state['on_hand']) {
                throw ValidationException::withMessages(["items.$index.quantity" => "Insufficient stock for {$product->name}. Reduce the total quantity in the cart."]);
            }
            if (! $preserveSnapshot && ! $state['is_available']) {
                throw ValidationException::withMessages(["items.$index.product_id" => 'This product is no longer available. Remove it or refresh the catalog.']);
            }
            /** A retained line of a Product removed from this Branch may stay or shrink, never sell more units. */
            if ($preserveSnapshot && $state['availability_reason'] === 'not_in_branch' && $line['quantity'] > $existing->quantity) {
                throw ValidationException::withMessages(["items.$index.quantity" => $product->name.' is no longer sold at this Branch. Keep or reduce its quantity.']);
            }
            $itemId = (string) Str::uuid();
            $base = ExactMoney::cents($preserveSnapshot ? $existing->unit_price : $state['effective_price']);
            $unit = $base;
            if ($preserveSnapshot) {
                foreach ($existing->modifiers as $modifier) {
                    if ($modifier->semantic_role_snapshot !== ModifierSemanticRole::Instruction->value) {
                        $unit = ExactMoney::add($unit, ExactMoney::cents($modifier->price_delta_snapshot));
                    }
                    $modifiers[] = [
                        'id' => (string) Str::uuid(), 'order_item_id' => $itemId,
                        'modifier_option_id' => $modifier->modifier_option_id,
                        'modifier_group_id_snapshot' => $modifier->modifier_group_id_snapshot,
                        'group_name_snapshot' => $modifier->group_name_snapshot,
                        'semantic_role_snapshot' => $modifier->semantic_role_snapshot,
                        'option_name_snapshot' => $modifier->option_name_snapshot,
                        'price_delta_snapshot' => $modifier->price_delta_snapshot,
                        'quantity' => 1,
                    ];
                }
            }
            $groups = $product->modifierGroups->keyBy('id');
            $selected = [];
            $counts = [];
            foreach ($preserveSnapshot ? [] : $line['modifiers'] as $selection) {
                $group = $groups->get($selection['group_id']);
                $option = $group?->options->firstWhere('id', $selection['option_id']);
                if ($group === null || $option === null || isset($selected[$selection['option_id']])) {
                    throw ValidationException::withMessages(["items.$index.modifiers" => 'A modifier is unavailable, duplicated, or does not belong to this product.']);
                }
                $selected[$option->id] = true;
                $counts[$group->id] = ($counts[$group->id] ?? 0) + 1;
                $isInstruction = $group->semantic_role === ModifierSemanticRole::Instruction;
                if ($isInstruction && ExactMoney::cents($option->price_delta) !== 0) {
                    throw ValidationException::withMessages(["items.$index.modifiers" => 'Instruction options cannot change the order price.']);
                }
                if (! $isInstruction) {
                    $unit = ExactMoney::add($unit, ExactMoney::cents($option->price_delta));
                }
                $modifiers[] = [
                    'id' => (string) Str::uuid(), 'order_item_id' => $itemId,
                    'modifier_option_id' => $option->id, 'modifier_group_id_snapshot' => $group->id, 'group_name_snapshot' => $group->name,
                    'semantic_role_snapshot' => $group->semantic_role?->value,
                    'option_name_snapshot' => $option->name,
                    'price_delta_snapshot' => $isInstruction ? '0.00' : $option->price_delta,
                    'quantity' => 1,
                ];
            }
            foreach ($preserveSnapshot ? [] : $groups as $group) {
                $count = $counts[$group->id] ?? 0;
                if ($count < $group->min_select || $count > $group->max_select
                    || ($group->selection_type === ModifierSelectionType::Single && $count > 1)) {
                    throw ValidationException::withMessages(["items.$index.modifiers" => "Choose the required number of options for {$group->name}."]);
                }
            }
            $lineTotal = ExactMoney::multiply($unit, $line['quantity']);
            $subtotal = ExactMoney::add($subtotal, $lineTotal);
            $items[] = [
                'id' => $itemId, 'order_id' => $orderId, 'product_id' => $product->id,
                'product_name_snapshot' => $preserveSnapshot ? $existing->product_name_snapshot : $product->name, 'unit_price' => ExactMoney::decimal($base),
                'quantity' => $line['quantity'], 'line_total' => ExactMoney::decimal($lineTotal),
                'notes' => $line['notes'] ?? null, 'created_at' => now(), 'updated_at' => now(),
            ];
        }
        /**
         * New orders (POS drafts and sales, Customer QR submissions) must fit current Recipe Ingredient stock across the
         * whole order. Committed-order edits are checked on their net delta when the edit is applied.
         */
        $recipeLines = array_values(array_filter($data['items'], fn (array $line): bool => (bool) $products->get($line['product_id'])?->getAttribute('has_recipe')));
        if ($existingItems === null && $recipeLines !== []) {
            $this->recipes->assertOrderFits($branch, array_map(fn (array $line): array => [
                'product_id' => $line['product_id'], 'quantity' => $line['quantity'], 'modifiers' => $line['modifiers'],
            ], $recipeLines));
        }

        return ['attributes' => [
            'order_type' => $type, 'branch_table_id' => $tableId,
            'customer_label' => $label === '' ? null : $label,
            'table_name_snapshot' => $tableId === null ? null : $branch->tables()->whereKey($tableId)->value('name'),
            'subtotal' => ExactMoney::decimal($subtotal), 'total' => ExactMoney::decimal($subtotal),
        ], 'items' => $items, 'modifiers' => $modifiers];
    }

    /** @param array{attributes: array<string, mixed>, items: list<array<string, mixed>>, modifiers: list<array<string, mixed>>} $snapshot */
    public function persist(array $snapshot): void
    {
        OrderItem::query()->insert($snapshot['items']);
        foreach (array_chunk($snapshot['modifiers'], 500) as $chunk) {
            OrderItemModifier::query()->insert($chunk);
        }
    }
}
