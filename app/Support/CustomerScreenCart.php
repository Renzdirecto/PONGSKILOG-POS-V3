<?php

namespace App\Support;

use App\Enums\CommercialStatus;
use App\Enums\ModifierSemanticRole;
use App\Models\Branch;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemModifier;

/**
 * The customer-safe Live Cart projection (Phase 19.6A). The POS sends only Product / option ids and quantities; every
 * visible text and amount is derived here from the canonical Branch catalog (`BranchCatalog`, the same Size naming as
 * `OperationalItemName` and the same unit-price rule as `OrderSnapshots`), so a client can neither inject text nor
 * show a price the Branch does not charge. Lines of a Product this Branch does not sell are dropped silently: the
 * projection is a display, never a validation or an order. It contains no ids (line keys are opaque hashes), no
 * free-text notes (they may be staff-internal), no stock, cost, tender or staff data.
 *
 * @phpstan-type CartLine array{key: string, name: string, quantity: int, details: list<string>, instructions: list<string>, amount: string}
 * @phpstan-type CartProjection array{lines: list<CartLine>, total: string, item_count: int}
 */
class CustomerScreenCart
{
    public const MAX_LINES = 60;

    public function __construct(private BranchCatalog $catalog) {}

    /**
     * @param  list<array{key: string, product_id: string, quantity: int, modifiers: list<array{group_id: string, option_id: string}>}>  $items
     * @return CartProjection
     */
    public function project(Branch $branch, string $screenId, array $items, ?Order $saved = null): array
    {
        $lines = [];
        if ($saved !== null) {
            foreach ($saved->items as $item) {
                $lines[] = $this->savedLine($screenId, $item);
            }
        }

        $products = $items === [] ? collect() : $this->catalog
            ->productsForOrder($branch, array_values(array_unique(array_column($items, 'product_id'))))
            ->keyBy('id');

        foreach ($items as $item) {
            $product = $products->get($item['product_id']);
            if ($product === null || $product->branchProducts->isEmpty()) {
                continue;
            }
            $unit = ExactMoney::cents($this->catalog->resolveLoaded($product)['effective_price']);
            $groups = $product->modifierGroups->keyBy('id');
            $size = null;
            $details = [];
            $instructions = [];
            foreach ($item['modifiers'] as $selection) {
                $group = $groups->get($selection['group_id']);
                $option = $group?->options->firstWhere('id', $selection['option_id']);
                if (! $group instanceof ModifierGroup || ! $option instanceof ModifierOption) {
                    continue;
                }
                if ($group->semantic_role === ModifierSemanticRole::Instruction) {
                    $instructions[] = $option->name;

                    continue;
                }
                $unit = ExactMoney::add($unit, ExactMoney::cents($option->price_delta));
                if ($group->semantic_role === ModifierSemanticRole::Size) {
                    $size = $option->name;
                } else {
                    $details[] = $option->name;
                }
            }
            $lines[] = [
                'key' => $this->lineKey($screenId, 'cart:'.$item['key']),
                'name' => $size === null ? $product->name : $size.' '.$product->name,
                'quantity' => $item['quantity'],
                'details' => $details,
                'instructions' => $instructions,
                'amount' => ExactMoney::decimal(ExactMoney::multiply($unit, $item['quantity'])),
            ];
        }

        $lines = array_slice($lines, 0, self::MAX_LINES);

        return [
            'lines' => $lines,
            'total' => ExactMoney::decimal(array_reduce($lines, fn (int $sum, array $line): int => ExactMoney::add($sum, ExactMoney::cents($line['amount'])), 0)),
            'item_count' => array_sum(array_column($lines, 'quantity')),
        ];
    }

    /**
     * A saved POS draft or a loaded Customer QR order the station is working on: not yet committed, at this Branch.
     */
    public function savedOrder(Branch $branch, ?string $orderId): ?Order
    {
        if ($orderId === null) {
            return null;
        }

        return Order::query()
            ->where('branch_id', $branch->getKey())
            ->whereKey($orderId)
            ->whereNull('committed_at')
            ->whereIn('commercial_status', [CommercialStatus::Draft, CommercialStatus::Submitted])
            ->with(['items' => fn ($query) => $query->orderBy('created_at')->orderBy('id'), 'items.modifiers'])
            ->first();
    }

    /** @return CartLine */
    private function savedLine(string $screenId, OrderItem $item): array
    {
        $modifiers = $item->modifiers;

        return [
            'key' => $this->lineKey($screenId, 'saved:'.$item->getKey()),
            'name' => OperationalItemName::fromOrderItem($item)['display_name'],
            'quantity' => $item->quantity,
            'details' => array_values($modifiers
                ->reject(fn (OrderItemModifier $modifier): bool => in_array($modifier->semantic_role_snapshot, [ModifierSemanticRole::Size->value, ModifierSemanticRole::Instruction->value], true))
                ->map(fn (OrderItemModifier $modifier): string => $modifier->option_name_snapshot)
                ->all()),
            'instructions' => array_values($modifiers
                ->filter(fn (OrderItemModifier $modifier): bool => $modifier->semantic_role_snapshot === ModifierSemanticRole::Instruction->value)
                ->map(fn (OrderItemModifier $modifier): string => $modifier->option_name_snapshot)
                ->all()),
            'amount' => (string) $item->line_total,
        ];
    }

    /** An opaque, stable per-line key (so the screen can highlight a changed line) that reveals no id. */
    private function lineKey(string $screenId, string $source): string
    {
        return substr(hash('sha256', $screenId.'|'.$source), 0, 16);
    }
}
