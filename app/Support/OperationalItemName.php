<?php

namespace App\Support;

use App\Enums\ModifierSemanticRole;
use App\Models\OrderItem;

class OperationalItemName
{
    /** @return array{name: string, size_prefix: string|null, display_name: string} */
    public static function fromOrderItem(OrderItem $item): array
    {
        $size = $item->modifiers->first(
            fn ($modifier): bool => $modifier->semantic_role_snapshot === ModifierSemanticRole::Size->value,
        );
        $prefix = $size?->option_name_snapshot;

        return [
            'name' => $item->product_name_snapshot,
            'size_prefix' => $prefix,
            'display_name' => $prefix === null ? $item->product_name_snapshot : $prefix.' '.$item->product_name_snapshot,
        ];
    }
}
