<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Str;

class QrOrderChanged implements ShouldBroadcastNow, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable;

    /** @var array<string, mixed> */
    private array $payload;

    public function __construct(Order $order, string $eventType)
    {
        $this->payload = [
            'event_id' => (string) Str::uuid(), 'event_type' => $eventType,
            'branch_id' => $order->branch_id, 'order_id' => $order->id,
            'order_number' => $order->order_number, 'version' => $order->version,
            ...($eventType === 'qr.order_archived'
                ? ['archive_reason' => $order->archive_reason, 'archived_at' => $order->archived_at?->toIso8601String()]
                : ['order_type' => $order->order_type->value, 'submitted_at' => $order->submitted_at?->toIso8601String()]),
        ];
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('branch.'.$this->payload['branch_id'].'.pos');
    }

    public function broadcastAs(): string
    {
        return $this->payload['event_type'];
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
