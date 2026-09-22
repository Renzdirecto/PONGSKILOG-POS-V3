<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Str;

class CustomerCatalogChanged implements ShouldBroadcastNow, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable;

    /** @var array<string, mixed> */
    private array $payload;

    public function __construct(string $branchId)
    {
        $this->payload = ['event_id' => (string) Str::uuid(), 'event_type' => 'qr.catalog_changed',
            'branch_id' => $branchId, 'occurred_at' => now()->toIso8601String()];
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('qr-catalog.'.$this->payload['branch_id']);
    }

    public function broadcastAs(): string
    {
        return 'qr.catalog_changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
