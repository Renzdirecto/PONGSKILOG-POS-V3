<?php

namespace App\Events;

use App\Models\AuditLog;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Str;

class AuditLogRecorded implements ShouldBroadcastNow, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable;

    /** @var array<string, string|int|null> */
    private array $payload;

    public function __construct(AuditLog $audit)
    {
        $this->payload = [
            'event_id' => (string) Str::uuid(),
            'event_type' => 'audit.recorded',
            'audit_id' => $audit->id,
            'branch_id' => $audit->branch_id,
            'occurred_at' => $audit->created_at->toIso8601String(),
        ];
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('audit-trail')];
    }

    public function broadcastAs(): string
    {
        return 'audit.recorded';
    }

    /** @return array<string, string|int|null> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
