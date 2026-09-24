<?php

namespace App\Enums;

enum IngredientMovementType: string
{
    case OpeningBalance = 'opening_balance';
    case SaleConsumption = 'sale_consumption';
    case OrderEditAdjustment = 'order_edit_adjustment';
    case VoidRestoration = 'void_restoration';
    case PurchaseRestock = 'purchase_restock';
    case Wastage = 'wastage';
    case CountCorrection = 'count_correction';

    public function label(): string
    {
        return match ($this) {
            self::OpeningBalance => 'Opening balance',
            self::SaleConsumption => 'Sale',
            self::OrderEditAdjustment => 'Edit',
            self::VoidRestoration => 'Void',
            self::PurchaseRestock => 'Purchase',
            self::Wastage => 'Wastage',
            self::CountCorrection => 'Count correction',
        };
    }

    /** Movements that belong to an Order's ingredient consumption (and so to estimated COGS). */
    public function isOrderConsumption(): bool
    {
        return in_array($this, [self::SaleConsumption, self::OrderEditAdjustment, self::VoidRestoration], true);
    }
}
