<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Str;

/**
 * Invalidation for one or more customer screens (pairing, mode, live cart, takeover or Branch advertisements). The
 * payload carries only an event id, a reason and the time: the screen refetches its own authoritative projection, so
 * cart lines, prices, order numbers and pickup tokens never travel in a broadcast. Each screen has its own private
 * channel (`customer-screen.{channel_key}`), so another station's cart can never reach it.
 */
class CustomerScreenChanged implements ShouldBroadcastNow, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable;

    /** @var array{event_id: string, event_type: string, reason: string, occurred_at: string} */
    private array $payload;

    /**
     * @param  list<string>  $channelKeys
     * @param  'pairing'|'mode'|'cart'|'takeover'|'ads'  $reason
     */
    public function __construct(private array $channelKeys, string $reason)
    {
        $this->payload = [
            'event_id' => (string) Str::uuid(),
            'event_type' => 'customer_screen.changed',
            'reason' => $reason,
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
        return array_map(fn (string $key): PrivateChannel => new PrivateChannel('customer-screen.'.$key), $this->channelKeys);
    }

    public function broadcastAs(): string
    {
        return 'customer_screen.changed';
    }

    /** @return array{event_id: string, event_type: string, reason: string, occurred_at: string} */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
