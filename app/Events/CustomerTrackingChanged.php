<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Str;

class CustomerTrackingChanged implements ShouldBroadcastNow, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable;

    /** @var array<string, mixed> */
    private array $payload;

    public function __construct(Order $order)
    {
        $this->payload = [
            'event_id' => (string) Str::uuid(), 'event_type' => 'order.tracking_changed',
            'public_tracking_id' => $order->public_tracking_id,
            'occurred_at' => now()->toIso8601String(), 'version' => $order->version,
        ];
    }

    public function broadcastWhen(): bool
    {
        return $this->payload['public_tracking_id'] !== null;
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('order-tracking.'.$this->payload['public_tracking_id']);
    }

    public function broadcastAs(): string
    {
        return 'order.tracking_changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
