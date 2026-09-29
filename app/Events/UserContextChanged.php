<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Str;

/**
 * Invalidation-only signal on the affected account's own private user channel: its identity (name, Position,
 * picture), access (Role, Role baseline, custom access, Branch assignments) or status changed. Open sessions of that
 * account revalidate against the server (shared auth props, Branch context, the current page's authorization). The
 * signal never carries permissions, email, credentials, Branch details or audit values.
 */
class UserContextChanged implements ShouldBroadcastNow, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable;

    public const IDENTITY = 'identity';

    public const ACCESS = 'access';

    public const BRANCHES = 'branches';

    public const STATUS = 'status';

    /** @var array{event_id: string, event_type: string, user_id: int, change_type: string, occurred_at: string} */
    private array $payload;

    public function __construct(public int $userId, string $changeType)
    {
        $this->payload = [
            'event_id' => (string) Str::uuid(),
            'event_type' => 'user.context_changed',
            'user_id' => $userId,
            'change_type' => $changeType,
            'occurred_at' => now()->toIso8601String(),
        ];
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('App.Models.User.'.$this->userId);
    }

    public function broadcastAs(): string
    {
        return 'user.context_changed';
    }

    /** @return array{event_id: string, event_type: string, user_id: int, change_type: string, occurred_at: string} */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
