<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Str;

/**
 * Invalidation-only signal for open Access Control pages (Super Admin access control only): a Role baseline, a Custom
 * Role, an account's custom access or the Staff list behind it changed. Clients reload their authorized projection.
 */
class AccessControlChanged implements ShouldBroadcastNow, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable;

    /** @var array{event_id: string, event_type: string, reason: string, occurred_at: string} */
    private array $payload;

    public function __construct(string $reason)
    {
        $this->payload = [
            'event_id' => (string) Str::uuid(),
            'event_type' => 'access_control.changed',
            'reason' => $reason,
            'occurred_at' => now()->toIso8601String(),
        ];
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('access-control');
    }

    public function broadcastAs(): string
    {
        return 'access_control.changed';
    }

    /** @return array{event_id: string, event_type: string, reason: string, occurred_at: string} */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
