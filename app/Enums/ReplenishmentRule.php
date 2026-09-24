<?php

namespace App\Enums;

enum ReplenishmentRule: string
{
    case TopUp = 'top_up';
    case Reorder = 'reorder';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::TopUp => 'Top up when below target',
            self::Reorder => 'Reorder at a threshold',
            self::None => 'No automatic suggestion',
        };
    }
}
