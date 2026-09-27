<?php

namespace App\Support;

use App\Enums\CommercialStatus;
use App\Enums\KitchenStatus;
use App\Enums\ModifierSemanticRole;
use App\Enums\OrderType;
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

    /**
     * The Customer Display board: order numbers (with their order type, for the Dine In / Take Out colors) in the
     * board's own order, and the Dine In / Take Out counts of its "Preparing" column — the active queue `queue()` ranks —
     * taken from the same rows, never from a second query.
     *
     * @return array{is_open: bool, preparing: list<array{number: string, order_type: string}>, ready: list<array{number: string, order_type: string}>, counts: array{dine_in: int, take_out: int}}
     */
    public function customerDisplay(Branch $branch): array
    {
        $session = $this->openSession($branch);

        if ($session === null) {
            return ['is_open' => false, 'preparing' => [], 'ready' => [], 'counts' => ['dine_in' => 0, 'take_out' => 0]];
        }

        $numbers = $this->ordersForSession($branch, $session)
            ->whereIn('kitchen_status', [
                KitchenStatus::Kitchen,
                KitchenStatus::Preparing,
                KitchenStatus::Ready,
            ])
            ->orderBy('committed_at')
            ->orderBy('id')
            ->get(['order_number', 'order_type', 'kitchen_status'])
            ->groupBy(fn (Order $order): string => $order->kitchen_status === KitchenStatus::Ready ? 'ready' : 'preparing');
        $preparing = $numbers->get('preparing', collect());
        $waitingByType = $preparing->countBy(fn (Order $order): string => $order->order_type->value);

        return [
            'is_open' => true,
            'preparing' => $this->numberList($preparing),
            'ready' => $this->numberList($numbers->get('ready', collect())),
            'counts' => [
                'dine_in' => (int) $waitingByType->get(OrderType::DineIn->value, 0),
                'take_out' => (int) $waitingByType->get(OrderType::TakeOut->value, 0),
            ],
        ];
    }

    /** How many queue rows a customer-facing queue list shows at most. */
    public const QUEUE_WINDOW = 10;

    /**
     * The one queue-position authority (Phase 19.6) used by the customer screen's order confirmation and the pickup
     * page. The active queue is the Customer Display "Preparing" column: committed orders of the OPEN Store Session
     * still in Kitchen or Preparing, in the board's own order (committed_at, id). Ready, Done, voided, archived,
     * uncommitted and earlier-session orders are not in it.
     *
     * - `overall_position`: the order's place among every active order (Dine In and Take Out together);
     * - `type_position`: its place among active orders of its own type only;
     * - `rows`: at most `$window` rows with their true absolute positions, always including this order (the first rows
     *   when it is near the front, otherwise the rows leading up to it and one after).
     *
     * Positions are null once the order itself is not waiting (Ready, Done, voided) or its Store Session is not the open
     * one; the rows then still show the current queue. One query reads the queue (four small columns), whatever its
     * length. No customer names, tables, items or ids are returned.
     *
     * @return array{overall_position: int|null, type_position: int|null, total: int, rows: list<array{position: int, order_number: string, order_type: string, current: bool}>}
     */
    public function queue(Order $order, int $window = self::QUEUE_WINDOW): array
    {
        $empty = ['overall_position' => null, 'type_position' => null, 'total' => 0, 'rows' => []];
        $branch = $order->relationLoaded('branch') ? $order->branch : $order->branch()->first();
        $session = $branch === null ? null : $this->openSession($branch);
        if ($branch === null || $session === null) {
            return $empty;
        }
        $active = $this->ordersForSession($branch, $session)
            ->whereIn('kitchen_status', [KitchenStatus::Kitchen, KitchenStatus::Preparing])
            ->orderBy('committed_at')
            ->orderBy('id')
            ->get(['id', 'order_number', 'order_type', 'committed_at']);

        $overall = null;
        $typePosition = null;
        $sameType = 0;
        foreach ($active->values() as $index => $row) {
            if ($row->order_type === $order->order_type) {
                $sameType++;
            }
            if ($row->getKey() === $order->getKey()) {
                $overall = $index + 1;
                $typePosition = $sameType;
            }
        }
        $total = $active->count();
        $window = max(1, $window);
        $start = $overall === null || $overall <= $window ? 1 : min($overall - $window + 2, $total - $window + 1);

        return [
            'overall_position' => $overall,
            'type_position' => $typePosition,
            'total' => $total,
            'rows' => array_values($active->slice($start - 1, $window)->values()->map(fn (Order $row, int $offset): array => [
                'position' => $start + $offset,
                'order_number' => (string) $row->order_number,
                'order_type' => $row->order_type->value,
                'current' => $row->getKey() === $order->getKey(),
            ])->all()),
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
                'pickupToken' => fn ($query) => $query
                    ->select(['id', 'order_id', 'expires_at', 'buzz_count', 'last_buzzed_at'])
                    ->withExists('pushSubscription'),
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
            'buzz' => app(PickupBuzzPolicy::class)->state(
                $order,
                $order->pickupToken,
                (bool) $order->pickupToken?->getAttribute('push_subscription_exists'),
            ),
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
     * @return list<array{number: string, order_type: string}>
     */
    private function numberList(Collection $orders): array
    {
        return array_values($orders
            ->map(fn (Order $order): array => [
                'number' => '#'.$order->order_number,
                'order_type' => $order->order_type->value,
            ])
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
