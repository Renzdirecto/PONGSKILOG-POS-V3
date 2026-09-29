<?php

namespace App\Enums;

/** Why a Product was given away free during a Store Session (a stock-out with ₱0 revenue, never an expense). */
enum GiveawayReason: string
{
    case Complimentary = 'complimentary';
    case ServiceRecovery = 'service_recovery';
    case Promotion = 'promotion';
    case StaffMeal = 'staff_meal';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Complimentary => 'Complimentary / on the house',
            self::ServiceRecovery => 'Service recovery',
            self::Promotion => 'Promo / sampling',
            self::StaffMeal => 'Staff meal',
            self::Other => 'Other',
        };
    }
}
