<?php

namespace App\Events;

use App\Models\KitchenTicket;
use App\Models\Order;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Str;

class KitchenTicketCreated implements ShouldBroadcast, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable;

    /** @var array<string, mixed> */
    private array $payload;

    public function __construct(Order $order, KitchenTicket $ticket)
    {
        $this->payload = [
            'event_id' => (string) Str::uuid(), 'event_type' => 'kitchen.ticket_created',
            'branch_id' => $order->branch_id, 'entity_id' => $order->id,
            'order_id' => $order->id, 'order_number' => $order->order_number,
            'occurred_at' => $order->committed_at?->toIso8601String(), 'version' => $order->version,
            'kitchen_ticket_id' => $ticket->id, 'order_type' => $order->order_type->value,
        ];
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        $branchId = $this->payload['branch_id'];

        return [
            new PrivateChannel('branch.'.$branchId.'.kitchen'),
            new PrivateChannel('branch.'.$branchId.'.pos'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'kitchen.ticket_created';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
