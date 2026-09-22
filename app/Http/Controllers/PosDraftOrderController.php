<?php

namespace App\Http\Controllers;

use App\Actions\Orders\CreatePosDraftOrder;
use App\Enums\CommercialStatus;
use App\Enums\OrderSource;
use App\Http\Requests\StorePosDraftOrderRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemModifier;
use App\Models\User;
use App\Support\ActiveBranchContext;
use App\Support\OperationalItemName;
use App\Support\PosAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PosDraftOrderController extends Controller
{
    public function store(StorePosDraftOrderRequest $request, ActiveBranchContext $context, CreatePosDraftOrder $create): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $branch = $context->current($user);
        abort_if($branch === null, 403);
        $reservedOrder = $request->filled('reserved_order_id')
            ? Order::query()->whereKey($request->validated('reserved_order_id'))->firstOrFail()
            : null;
        $order = $create->execute($user, $branch, $request->validated(), $reservedOrder);

        Inertia::flash('posDraft', $this->summary($order));

        return to_route('workspaces.cashier');
    }

    public function show(Request $request, Order $order, ActiveBranchContext $context, PosAccess $access): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $branch = $context->current($user);
        abort_if($branch === null, 403);
        $access->authorize($user, $branch);
        abort_unless($order->branch_id === $branch->id && $order->source === OrderSource::Pos
            && $order->commercial_status === CommercialStatus::Draft, 404);

        return Inertia::render('workspaces/order-summary', [
            'order' => $this->summary($order),
        ]);
    }

    /** @return array<string, mixed> */
    private function summary(Order $order): array
    {
        $order->load('items.modifiers', 'branchTable');

        return [
            'id' => $order->id, 'order_number' => $order->order_number, 'reference_number' => $order->reference_number,
            'order_type' => $order->order_type->value, 'customer_label' => $order->customer_label,
            'table_name' => $order->branchTable?->name, 'subtotal' => $order->subtotal, 'total' => $order->total,
            'items' => $order->items->map(fn (OrderItem $item): array => [
                'id' => $item->id, ...OperationalItemName::fromOrderItem($item), 'unit_price' => $item->unit_price,
                'quantity' => $item->quantity, 'line_total' => $item->line_total, 'notes' => $item->notes,
                'modifiers' => $item->modifiers->map(fn (OrderItemModifier $modifier): array => [
                    'id' => $modifier->id, 'group_name' => $modifier->group_name_snapshot,
                    'semantic_role' => $modifier->semantic_role_snapshot,
                    'name' => $modifier->option_name_snapshot, 'price_delta' => $modifier->price_delta_snapshot,
                    'quantity' => $modifier->quantity,
                ])->all(),
            ])->all(),
        ];
    }
}
