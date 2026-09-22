<?php

namespace App\Events;

use App\Enums\KitchenStatus;
use App\Models\Order;
use Carbon\CarbonInterface;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Str;

class KitchenStatusChanged implements ShouldBroadcast, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable;

    /** @var array<string, mixed> */
    private array $payload;

    public function __construct(Order $order, KitchenStatus $from, KitchenStatus $to, CarbonInterface $changedAt)
    {
        $this->payload = [
            'event_id' => (string) Str::uuid(),
            'event_type' => 'kitchen.status_changed',
            'branch_id' => $order->branch_id,
            'entity_id' => $order->id,
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'from' => $from->value,
            'to' => $to->value,
            'occurred_at' => $changedAt->toIso8601String(),
            'version' => $order->version,
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
        return 'kitchen.status_changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
