<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Str;

class ProductBranchConfigurationChanged implements ShouldBroadcast, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable;

    /** @var array<string, mixed> */
    private array $payload;

    public function __construct(
        string $branchId,
        string $productId,
        bool $isAvailable,
        string $effectivePrice,
        int $version,
    ) {
        $this->payload = [
            'event_id' => (string) Str::uuid(),
            'event_type' => 'product.branch_configuration_changed',
            'branch_id' => $branchId,
            'entity_id' => $productId,
            'product_id' => $productId,
            'is_available' => $isAvailable,
            'effective_price' => $effectivePrice,
            'occurred_at' => now()->toIso8601String(),
            'version' => $version,
        ];
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('branch.'.$this->payload['branch_id'].'.inventory')];
    }

    public function broadcastAs(): string
    {
        return 'product.branch_configuration_changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
