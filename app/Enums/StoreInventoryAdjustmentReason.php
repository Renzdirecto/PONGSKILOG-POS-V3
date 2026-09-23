<?php

namespace App\Enums;

enum StoreInventoryAdjustmentReason: string
{
    case Complimentary = 'complimentary';
    case Wastage = 'wastage';
    case Damaged = 'damaged';
    case StaffMeal = 'staff_meal';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Complimentary => 'Complimentary / Free item',
            self::Wastage => 'Wastage',
            self::Damaged => 'Damaged',
            self::StaffMeal => 'Staff meal',
            self::Other => 'Other',
        };
    }
}
