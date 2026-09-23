<?php

namespace App\Support;

use App\Enums\CommercialStatus;
use App\Enums\KitchenStatus;
use App\Enums\ModifierSemanticRole;
use App\Enums\StoreSessionStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemModifier;
use App\Models\StoreSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class KitchenBoard
{
    /**
     * @return array{
     *     is_open: bool,
     *     tickets: list<array<string, mixed>>,
     *     counts: array{all: int, kitchen: int, preparing: int, ready: int, done: int}
     * }
     */
    public function kitchen(Branch $branch): array
    {
        $session = $this->openSession($branch);

        if ($session === null) {
            return [
                'is_open' => false,
                'tickets' => [],
                'counts' => $this->emptyCounts(),
            ];
        }

        $activeOrders = $this->ordersForSession($branch, $session)
            ->whereIn('kitchen_status', [
                KitchenStatus::Kitchen,
                KitchenStatus::Preparing,
                KitchenStatus::Ready,
            ])
            ->with($this->kitchenRelations())
            ->orderBy('committed_at')
            ->orderBy('id')
            ->get();

        $doneOrders = $this->ordersForSession($branch, $session)
            ->where('kitchen_status', KitchenStatus::Done)
            ->with($this->kitchenRelations())
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return [
            'is_open' => true,
            'tickets' => array_values($activeOrders
                ->concat($doneOrders)
                ->filter($this->hasConsistentTicket(...))
                ->map($this->kitchenTicket(...))
                ->values()
                ->all()),
            'counts' => $this->countsForSession($branch, $session),
        ];
    }

    /**
     * Kitchen ticket counts for the current OPEN Store Session without loading tickets.
     *
     * @return array{all: int, kitchen: int, preparing: int, ready: int, done: int}
     */
    public function counts(Branch $branch): array
    {
        $session = $this->openSession($branch);

        return $session === null ? $this->emptyCounts() : $this->countsForSession($branch, $session);
    }

    /** @return array{is_open: bool, preparing: list<string>, ready: list<string>} */
    public function customerDisplay(Branch $branch): array
    {
        $session = $this->openSession($branch);

        if ($session === null) {
            return ['is_open' => false, 'preparing' => [], 'ready' => []];
        }

        $numbers = $this->ordersForSession($branch, $session)
            ->whereIn('kitchen_status', [
                KitchenStatus::Kitchen,
                KitchenStatus::Preparing,
                KitchenStatus::Ready,
            ])
            ->orderBy('committed_at')
            ->orderBy('id')
            ->get(['order_number', 'kitchen_status'])
            ->groupBy(fn (Order $order): string => $order->kitchen_status === KitchenStatus::Ready ? 'ready' : 'preparing');

        return [
            'is_open' => true,
            'preparing' => $this->numberList($numbers->get('preparing', collect())),
            'ready' => $this->numberList($numbers->get('ready', collect())),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function readyForPos(Branch $branch): array
    {
        $session = $this->openSession($branch);

        if ($session === null) {
            return [];
        }

        return array_values($this->ordersForSession($branch, $session)
            ->where('kitchen_status', KitchenStatus::Ready)
            ->with([
                ...$this->kitchenRelations(),
                'payments:id,order_id,method',
            ])
            ->orderBy('committed_at')
            ->orderBy('id')
            ->get()
            ->filter($this->hasConsistentTicket(...))
            ->map($this->posReadyOrder(...))
            ->values()
            ->all());
    }

    /** @return array{is_open: bool, dine_in: int, take_out: int} */
    public function statusForPos(Branch $branch): array
    {
        $session = $this->openSession($branch);

        if ($session === null) {
            return ['is_open' => false, 'dine_in' => 0, 'take_out' => 0];
        }

        $counts = $this->ordersForSession($branch, $session)
            ->whereIn('kitchen_status', [
                KitchenStatus::Kitchen,
                KitchenStatus::Preparing,
                KitchenStatus::Ready,
            ])
            ->selectRaw('order_type, count(*) as aggregate')
            ->groupBy('order_type')
            ->pluck('aggregate', 'order_type');

        return [
            'is_open' => true,
            'dine_in' => (int) ($counts['dine_in'] ?? 0),
            'take_out' => (int) ($counts['take_out'] ?? 0),
        ];
    }

    private function openSession(Branch $branch): ?StoreSession
    {
        return StoreSession::query()
            ->whereBelongsTo($branch)
            ->where('status', StoreSessionStatus::Open)
            ->latest('opened_at')
            ->first();
    }

    /** @return Builder<Order> */
    private function ordersForSession(Branch $branch, StoreSession $session): Builder
    {
        return Order::query()
            ->whereBelongsTo($branch)
            ->whereBelongsTo($session, 'storeSession')
            ->whereNotNull('committed_at')
            ->whereIn('commercial_status', [CommercialStatus::Active, CommercialStatus::Completed])
            ->whereHas('kitchenTicket');
    }

    /** @return array<int|string, string|(\Closure(\Illuminate\Database\Eloquent\Relations\Relation<*, *, *>): mixed)> */
    private function kitchenRelations(): array
    {
        return [
            'branchTable:id,name',
            'kitchenTicket:id,order_id,status',
            'items' => fn ($query) => $query
                ->select(['id', 'order_id', 'product_name_snapshot', 'quantity', 'notes', 'created_at'])
                ->orderBy('created_at')
                ->orderBy('id'),
            'items.modifiers' => fn ($query) => $query
                ->select([
                    'id', 'order_item_id', 'group_name_snapshot', 'semantic_role_snapshot',
                    'option_name_snapshot', 'quantity',
                ])
                ->orderBy('id'),
        ];
    }

    private function hasConsistentTicket(Order $order): bool
    {
        return $order->kitchenTicket !== null
            && $order->kitchenTicket->status === $order->kitchen_status;
    }

    /** @return array<string, mixed> */
    private function kitchenTicket(Order $order): array
    {
        return [
            'id' => (string) $order->getKey(),
            'number' => (string) $order->order_number,
            'customer' => $order->customer_label,
            'order_type' => $order->order_type->value,
            'table' => $order->branchTable?->name,
            'status' => $order->kitchen_status->value,
            'placed_at' => $order->committed_at?->toIso8601String(),
            'version' => $order->version,
            'items' => $order->items->map($this->operationalItem(...))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function posReadyOrder(Order $order): array
    {
        return [
            ...$this->kitchenTicket($order),
            'payment_status' => $order->payment_status->value,
            'payment_term' => $order->payment_term?->value,
            'payment_methods' => $order->payments
                ->pluck('method')
                ->map(fn ($method): string => $method->value)
                ->unique()
                ->values()
                ->all(),
            'total' => $order->total,
        ];
    }

    /** @return array<string, mixed> */
    private function operationalItem(OrderItem $item): array
    {
        $name = OperationalItemName::fromOrderItem($item);
        $instructions = $item->modifiers
            ->filter(fn (OrderItemModifier $modifier): bool => $modifier->semantic_role_snapshot === ModifierSemanticRole::Instruction->value)
            ->map(fn (OrderItemModifier $modifier): string => $modifier->option_name_snapshot)
            ->values();
        $modifiers = $item->modifiers
            ->reject(fn (OrderItemModifier $modifier): bool => in_array($modifier->semantic_role_snapshot, [
                ModifierSemanticRole::Size->value,
                ModifierSemanticRole::Instruction->value,
            ], true))
            ->map(fn (OrderItemModifier $modifier): string => $modifier->group_name_snapshot.': '.$modifier->option_name_snapshot)
            ->values();

        return [
            'id' => (string) $item->getKey(),
            'display_name' => $name['display_name'],
            'quantity' => $item->quantity,
            'standard_modifiers' => $modifiers->all(),
            'instructions' => $instructions->all(),
            'note' => filled($item->notes) ? $item->notes : null,
        ];
    }

    /**
     * @param  Collection<int, Order>  $orders
     * @return list<string>
     */
    private function numberList(Collection $orders): array
    {
        return array_values($orders
            ->map(fn (Order $order): string => '#'.$order->order_number)
            ->values()
            ->all());
    }

    /** @return array{all: int, kitchen: int, preparing: int, ready: int, done: int} */
    private function countsForSession(Branch $branch, StoreSession $session): array
    {
        $countsByStatus = $this->ordersForSession($branch, $session)
            ->selectRaw('kitchen_status, count(*) as aggregate')
            ->groupBy('kitchen_status')
            ->pluck('aggregate', 'kitchen_status');

        return [
            'all' => (int) $countsByStatus->only([
                KitchenStatus::Kitchen->value,
                KitchenStatus::Preparing->value,
                KitchenStatus::Ready->value,
            ])->sum(),
            'kitchen' => (int) ($countsByStatus[KitchenStatus::Kitchen->value] ?? 0),
            'preparing' => (int) ($countsByStatus[KitchenStatus::Preparing->value] ?? 0),
            'ready' => (int) ($countsByStatus[KitchenStatus::Ready->value] ?? 0),
            'done' => (int) ($countsByStatus[KitchenStatus::Done->value] ?? 0),
        ];
    }

    /** @return array{all: int, kitchen: int, preparing: int, ready: int, done: int} */
    private function emptyCounts(): array
    {
        return ['all' => 0, 'kitchen' => 0, 'preparing' => 0, 'ready' => 0, 'done' => 0];
    }
}
