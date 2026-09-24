<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Str;

/**
 * Invalidation-only signal on the recipient's own private user channel: their notifications changed. Clients refetch
 * the authorized unread count or list; the signal never carries a title, body, audit payload or credential.
 */
class NotificationsChanged implements ShouldBroadcastNow, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable;

    /** @var array{event_id: string, event_type: string, occurred_at: string} */
    private array $payload;

    public function __construct(public int $userId)
    {
        $this->payload = [
            'event_id' => (string) Str::uuid(),
            'event_type' => 'notifications.changed',
            'occurred_at' => now()->toIso8601String(),
        ];
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('App.Models.User.'.$this->userId);
    }

    public function broadcastAs(): string
    {
        return 'notifications.changed';
    }

    /** @return array{event_id: string, event_type: string, occurred_at: string} */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
