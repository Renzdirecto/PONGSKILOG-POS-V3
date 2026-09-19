<?php

namespace App\Support;

use App\Models\BranchInventory;
use App\Models\BranchProduct;

class InventoryState
{
    /** @return array{tracked: bool, on_hand: int|null, low_stock_threshold: int|null, status: 'not_tracked'|'in_stock'|'low_stock'|'out_of_stock'} */
    public function resolve(?BranchProduct $configuration, ?BranchInventory $balance): array
    {
        $tracked = $configuration->tracks_inventory ?? false;
        $onHand = $tracked ? ($balance->on_hand ?? 0) : null;
        $threshold = $configuration?->low_stock_threshold;

        return [
            'tracked' => $tracked,
            'on_hand' => $onHand,
            'low_stock_threshold' => $threshold,
            'status' => match (true) {
                ! $tracked => 'not_tracked',
                $onHand <= 0 => 'out_of_stock',
                $threshold !== null && $onHand <= $threshold => 'low_stock',
                default => 'in_stock',
            },
        ];
    }
}
