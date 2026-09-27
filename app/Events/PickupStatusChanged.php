<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Str;

/**
 * Invalidation for public pickup pages: an order's status or its place in the Take Out queue may have changed. Each
 * pickup page listens only on its own high-entropy channel (`pickup.{channel_key}`); one event reaches every affected
 * page in one broadcast (the broadcaster sends channels in batches). The payload carries no order, Branch or token.
 */
class PickupStatusChanged implements ShouldBroadcastNow, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable;

    /** @var array{event_id: string, event_type: string, occurred_at: string} */
    private array $payload;

    /** @param list<string> $channelKeys */
    public function __construct(private array $channelKeys)
    {
        $this->payload = [
            'event_id' => (string) Str::uuid(),
            'event_type' => 'pickup.changed',
            'occurred_at' => now()->toIso8601String(),
        ];
    }

    public function broadcastWhen(): bool
    {
        return $this->channelKeys !== [];
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return array_map(fn (string $key): PrivateChannel => new PrivateChannel('pickup.'.$key), $this->channelKeys);
    }

    public function broadcastAs(): string
    {
        return 'pickup.changed';
    }

    /** @return array{event_id: string, event_type: string, occurred_at: string} */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
